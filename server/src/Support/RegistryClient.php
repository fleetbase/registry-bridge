<?php

namespace Fleetbase\RegistryBridge\Support;

use Fleetbase\RegistryBridge\Exceptions\RegistryServiceException;
use Fleetbase\RegistryBridge\Exceptions\RegistryUnavailableException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the Registry Service: the public catalog (`/v1/catalog/*`) and the instance API
 * (`/v1/instances/*`), authenticated with the instance token issued at registration.
 *
 * @phpstan-consistent-constructor
 *
 * Successful catalog responses are cached (15 minutes; instance state for one minute) and the last good
 * payload is kept indefinitely, so an outage degrades to stale data instead of an empty Explore page. After a
 * few consecutive failures the circuit opens and the registry is not contacted again until the cool-down has
 * passed. Client errors (4xx) never count as failures; they surface as `RegistryServiceException`.
 *
 * On the cloud instance every instance request names the company it is for (`X-Registry-Tenant`, the current
 * session's company unless `forCompany()` names one), so licences, installs and checkouts belong to that company's
 * organization; cached instance state is kept per company.
 */
class RegistryClient
{
    /** How long a successful catalog response is served from cache, in minutes. */
    public const CACHE_TTL_MINUTES = 15;

    /** How long instance state (`me`, `updates`) is served from cache, in seconds. */
    public const INSTANCE_CACHE_SECONDS = 60;

    /** Consecutive failures before the circuit opens. */
    public const CIRCUIT_FAILURE_THRESHOLD = 3;

    /** How long the circuit stays open before the registry is retried, in seconds. */
    public const CIRCUIT_OPEN_SECONDS = 60;

    /** Timeout for a single registry request, in seconds. */
    public const TIMEOUT_SECONDS = 10;

    /** Page size used when walking the whole service catalog. */
    public const CATALOG_PAGE_SIZE = 100;

    /** Upper bound on catalog pages walked, so a misbehaving service cannot loop forever. */
    public const CATALOG_MAX_PAGES = 50;

    /** Registry Service endpoints, relative to the service URL. */
    public const CATALOG_EXTENSIONS_PATH = 'v1/catalog/extensions';

    public const CATALOG_CATEGORIES_PATH = 'v1/catalog/categories';

    public const INSTANCE_REGISTER_PATH = 'v1/instances/register';

    /** The cloud console's API registers itself here, signed with the platform secret. */
    public const INSTANCE_REGISTER_CLOUD_PATH = 'v1/instances/register/cloud';

    /** Cloud instance only: what each company has installed, for `registry:cloud-sync-installs`. */
    public const INSTANCE_TENANT_INSTALLS_PATH = 'v1/instances/me/tenant-installs';

    public const TENANT_HEADER = 'X-Registry-Tenant';

    public const TENANT_NAME_HEADER = 'X-Registry-Tenant-Name';

    public const INSTANCE_PATH = 'v1/instances/me';

    public const INSTANCE_UPDATES_PATH = 'v1/instances/me/updates';

    public const INSTANCE_INSTALLS_PATH = 'v1/instances/me/installs';

    /** Phase 4: hosted Stripe Checkout for the instance's organization, and the hand-off polled after it. */
    public const INSTANCE_CHECKOUT_PATH = 'v1/instances/me/purchases/checkout';

    public const INSTANCE_PURCHASES_PATH = 'v1/instances/me/purchases';

    protected const CACHE_PREFIX = 'registry-bridge:registry-client';

    protected string $host;

    protected ?string $token;

    protected bool $actsForCompanies;

    /** Company this client's instance requests are for; null uses the session's company. */
    protected ?string $company = null;

    /** @var array<string, string|null> company uuid => name, looked up once per process */
    protected static array $companyNames = [];

    /** @var (\Closure(string): ?string)|null looks up a company's name; set by the service provider */
    protected static ?\Closure $companyNameResolver = null;

    /**
     * @param string|null         $host   service URL; defaults to the configured one
     * @param string|null         $token  instance token; defaults to the configured one
     * @param RegistryConfig|null $config settings to resolve defaults from; defaults to the running config
     */
    public function __construct(?string $host = null, ?string $token = null, ?RegistryConfig $config = null)
    {
        $config                 = $config ?? RegistryConfig::current();
        $this->host             = static::normalizeHost($host ?: ($config->serviceUrl() ?? RegistryConfig::DEFAULT_SERVICE_URL));
        $this->token            = $token !== null && $token !== '' ? $token : $config->instanceToken();
        $this->actsForCompanies = $config->actsForCompanies();
    }

    /**
     * A copy whose instance requests are for this company (queued jobs have no session to take it from).
     */
    public function forCompany(?string $company): static
    {
        $client          = clone $this;
        $client->company = $company !== null && trim($company) !== '' ? trim($company) : null;

        return $client;
    }

    /**
     * The company instance requests are for on the cloud instance: the one named with `forCompany()`, else the
     * current session's. Null elsewhere.
     */
    public function company(): ?string
    {
        if (!$this->actsForCompanies) {
            return null;
        }

        if ($this->company !== null) {
            return $this->company;
        }

        try {
            $company = app()->bound('session') ? session('company') : null;
        } catch (\Throwable $e) {
            $company = null;
        }

        return is_string($company) && $company !== '' ? $company : null;
    }

    /**
     * Client for the given settings.
     */
    public static function fromConfig(RegistryConfig $config): static
    {
        return new static(null, null, $config);
    }

    /**
     * The service URL this client talks to.
     */
    public function host(): string
    {
        return $this->host;
    }

    /**
     * Whether a bearer token is attached to requests.
     */
    public function hasToken(): bool
    {
        return $this->token !== null;
    }

    /**
     * one page of the service catalog (`GET /v1/catalog/extensions`).
     *
     * Accepts the service filters (`q`, `category`, `pricing`, `hosting`, `publisher`, `tag`, `sort`, `page`, `limit`).
     *
     * @param array<string, mixed> $query
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     *
     * @throws RegistryUnavailableException|RegistryServiceException
     */
    public function catalogExtensions(array $query = []): array
    {
        $payload = $this->cachedGet(static::CATALOG_EXTENSIONS_PATH, array_filter($query, fn ($value) => $value !== null && $value !== ''));

        return [
            'data' => static::extractList($payload),
            'meta' => is_array($payload) && is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
        ];
    }

    /**
     * every published listing, walking the catalog pages.
     *
     * @param array<string, mixed> $query
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RegistryUnavailableException|RegistryServiceException
     */
    public function allCatalogExtensions(array $query = []): array
    {
        $extensions = [];
        $page       = 1;

        do {
            $result     = $this->catalogExtensions(array_merge($query, ['page' => $page, 'limit' => static::CATALOG_PAGE_SIZE]));
            $extensions = array_merge($extensions, $result['data']);
            $lastPage   = is_numeric($result['meta']['last_page'] ?? null) ? (int) $result['meta']['last_page'] : 1;
            $page++;
        } while ($page <= $lastPage && $page <= static::CATALOG_MAX_PAGES && $result['data'] !== []);

        return $extensions;
    }

    /**
     * one full listing by slug or `ext_` id (`GET /v1/catalog/extensions/{slug}`), or null when it does not exist.
     *
     * @return array<string, mixed>|null
     *
     * @throws RegistryUnavailableException|RegistryServiceException
     */
    public function catalogExtension(string $slug): ?array
    {
        try {
            $payload = $this->cachedGet(static::CATALOG_EXTENSIONS_PATH . '/' . rawurlencode($slug));
        } catch (RegistryServiceException $e) {
            if ($e->status() === 404) {
                return null;
            }

            throw $e;
        }

        return is_array($payload) ? static::unwrap($payload) : null;
    }

    /**
     * published and yanked releases of a listing (`GET /v1/catalog/extensions/{slug}/releases`), newest
     * first, or null when the listing does not exist.
     *
     * @return array{extension: string|null, latest: string|null, channels: array<string, string>, releases: array<int, array<string, mixed>>}|null
     *
     * @throws RegistryUnavailableException|RegistryServiceException
     */
    public function catalogReleases(string $slug): ?array
    {
        try {
            $payload = $this->cachedGet(static::CATALOG_EXTENSIONS_PATH . '/' . rawurlencode($slug) . '/releases');
        } catch (RegistryServiceException $e) {
            if ($e->status() === 404) {
                return null;
            }

            throw $e;
        }

        $payload  = is_array($payload) ? static::unwrap($payload) : [];
        $channels = is_array($payload['channels'] ?? null) ? array_filter($payload['channels'], 'is_string') : [];

        return [
            'extension' => is_string($payload['extension'] ?? null) ? $payload['extension'] : null,
            'latest'    => is_string($payload['latest'] ?? null) ? $payload['latest'] : null,
            'channels'  => $channels,
            'releases'  => is_array($payload['releases'] ?? null) ? array_values(array_filter($payload['releases'], 'is_array')) : [],
        ];
    }

    /**
     * categories with published extension counts (`GET /v1/catalog/categories`).
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RegistryUnavailableException|RegistryServiceException
     */
    public function catalogCategories(): array
    {
        return static::extractList($this->cachedGet(static::CATALOG_CATEGORIES_PATH));
    }

    /**
     * this instance, its organization and the organization's licences (`GET /v1/instances/me`).
     *
     * @return array{instance: array<string, mixed>, licences: array<int, array<string, mixed>>}
     *
     * @throws RegistryUnavailableException|RegistryServiceException
     */
    public function instance(): array
    {
        $this->requireToken();
        $payload = $this->cachedGet(static::INSTANCE_PATH, [], static::INSTANCE_CACHE_SECONDS);
        $payload = is_array($payload) ? $payload : [];

        return [
            'instance' => is_array($payload['instance'] ?? null) ? $payload['instance'] : [],
            'licences' => is_array($payload['licences'] ?? null) ? array_values(array_filter($payload['licences'], 'is_array')) : [],
        ];
    }

    /**
     * Phase 4: start a hosted Stripe Checkout for the organization (`POST /v1/instances/me/purchases/checkout`).
     *
     * A service without the endpoint answers 404 or 405, which surfaces as a `RegistryServiceException`.
     *
     * @param array<string, mixed> $payload `extension` (slug, `ext_` id or package name), `return_url`, optional `cancel_url`, `email`
     *
     * @return array{url: string, handoff: string, status: string, amount: int|null, currency: string|null}
     *
     * @throws RegistryUnavailableException|RegistryServiceException
     */
    public function createCheckout(array $payload): array
    {
        $this->requireToken();
        $body = static::unwrap($this->send('post', static::INSTANCE_CHECKOUT_PATH, array_filter($payload, fn ($value) => $value !== null && $value !== '')));

        $url     = $body['url'] ?? null;
        $handoff = $body['handoff'] ?? null;
        if (!is_string($url) || $url === '' || !is_string($handoff) || $handoff === '') {
            throw new RegistryServiceException('The registry service did not return a checkout URL and hand-off id.', 502);
        }

        return [
            'url'      => $url,
            'handoff'  => $handoff,
            'status'   => is_string($body['status'] ?? null) ? $body['status'] : 'open',
            'amount'   => is_numeric($body['amount'] ?? null) ? (int) $body['amount'] : null,
            'currency' => is_string($body['currency'] ?? null) ? $body['currency'] : null,
        ];
    }

    /**
     * Phase 4: the status of a checkout hand-off (`GET /v1/instances/me/purchases/{handoff}`), never cached.
     *
     * @return array<string, mixed> at least `status`
     *
     * @throws RegistryUnavailableException|RegistryServiceException
     */
    public function purchase(string $handoff): array
    {
        $this->requireToken();
        $body = static::unwrap($this->send('get', static::INSTANCE_PURCHASES_PATH . '/' . static::encodePathSegment($handoff)));
        // A completed hand-off means a new licence in `me`
        if (($body['status'] ?? null) === 'complete') {
            $this->forgetInstanceState();
        }

        return $body;
    }

    /**
     * installed releases against the newest compatible release (`GET /v1/instances/me/updates`).
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     *
     * @throws RegistryUnavailableException|RegistryServiceException
     */
    public function updates(?string $channel = null): array
    {
        $this->requireToken();
        $payload = $this->cachedGet(static::INSTANCE_UPDATES_PATH, $channel ? ['channel' => $channel] : [], static::INSTANCE_CACHE_SECONDS);

        return [
            'data' => static::extractList($payload),
            'meta' => is_array($payload) && is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
        ];
    }

    /**
     * exchange a one-time registration code for an instance token (`POST /v1/instances/register`).
     *
     * @param array<string, mixed> $payload `code`, `name`, `kind`, and optional `host`, `console_version`, `api_version`, `previous_token`
     *
     * @return array{token: string, token_type: string, rotated: bool, instance: array<string, mixed>} `rotated` is true
     *         when the service re-issued the token of an instance it already had (same id, installs and seats)
     *
     * @throws RegistryUnavailableException|RegistryServiceException
     */
    public function registerInstance(array $payload): array
    {
        $body = $this->send('post', static::INSTANCE_REGISTER_PATH, $payload, false);

        if (!is_string($body['token'] ?? null) || $body['token'] === '') {
            throw new RegistryServiceException('The registry service did not return an instance token.', 502);
        }

        return [
            'token'      => $body['token'],
            'token_type' => is_string($body['token_type'] ?? null) ? $body['token_type'] : 'Bearer',
            'rotated'    => ($body['rotated'] ?? false) === true,
            'instance'   => is_array($body['instance'] ?? null) ? $body['instance'] : [],
        ];
    }

    /**
     * Register this API as the cloud instance (`POST /v1/instances/register/cloud`). The body is sent exactly as
     * signed; see `CloudRegistration`.
     *
     * @param array<string, string> $signature the platform signature headers
     *
     * @return array{token: string, token_type: string, rotated: bool, instance: array<string, mixed>}
     *
     * @throws RegistryUnavailableException|RegistryServiceException
     */
    public function registerCloudInstance(string $body, array $signature): array
    {
        try {
            $response = $this->request(false)->withHeaders($signature)->withBody($body, 'application/json')->post(static::INSTANCE_REGISTER_CLOUD_PATH);
        } catch (\Throwable $e) {
            throw new RegistryUnavailableException('Unable to reach the extensions registry at ' . $this->host . ': ' . $e->getMessage(), 0, $e);
        }

        $payload = $this->payloadFromResponse($response);
        $payload = is_array($payload) ? $payload : [];
        if (!is_string($payload['token'] ?? null) || $payload['token'] === '') {
            throw new RegistryServiceException('The registry service did not return an instance token.', 502);
        }

        return [
            'token'      => $payload['token'],
            'token_type' => is_string($payload['token_type'] ?? null) ? $payload['token_type'] : 'Bearer',
            'rotated'    => ($payload['rotated'] ?? false) === true,
            'instance'   => is_array($payload['instance'] ?? null) ? $payload['instance'] : [],
        ];
    }

    /**
     * Whether the service accepts the instance token, uncached: true, false when it answers 401 or 403 (revoked,
     * rotated by another process), null when it cannot be asked (no token, unreachable, a server error).
     */
    public function instanceTokenAccepted(): ?bool
    {
        if (!$this->hasToken()) {
            return null;
        }

        try {
            $status = $this->request()->get(static::INSTANCE_PATH)->status();
        } catch (\Throwable $e) {
            return null;
        }

        return match (true) {
            $status >= 200 && $status < 300     => true,
            in_array($status, [401, 403], true) => false,
            default                             => null,
        };
    }

    /**
     * Cloud instance only: one page of what every company has installed (`GET /v1/instances/me/tenant-installs`).
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     *
     * @throws RegistryUnavailableException|RegistryServiceException
     */
    public function tenantInstalls(int $page = 1): array
    {
        $this->requireToken();
        $payload = $this->send('get', static::INSTANCE_TENANT_INSTALLS_PATH, ['page' => $page]);

        return [
            'data' => static::extractList($payload),
            'meta' => is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
        ];
    }

    /**
     * report an install attempt (`POST /v1/instances/me/installs`).
     *
     * @param array<string, mixed> $payload `extension`, `version` or `release`, `status` (installing, installed, failed), optional `error`, `installed_by`
     *
     * @return array{install: array<string, mixed>|null, activation: array<string, mixed>|null}
     *
     * @throws RegistryUnavailableException|RegistryServiceException
     */
    public function reportInstall(array $payload): array
    {
        $this->requireToken();
        $body = $this->send('post', static::INSTANCE_INSTALLS_PATH, array_filter($payload, fn ($value) => $value !== null));
        $this->forgetInstanceState();

        return [
            'install'    => is_array($body['install'] ?? null) ? $body['install'] : null,
            'activation' => is_array($body['activation'] ?? null) ? $body['activation'] : null,
        ];
    }

    /**
     * report an uninstall (`DELETE /v1/instances/me/installs/{extension}`). Scoped npm names work in the path.
     *
     * @return array{install: array<string, mixed>|null, deactivated: int}
     *
     * @throws RegistryUnavailableException|RegistryServiceException
     */
    public function reportUninstall(string $extension): array
    {
        $this->requireToken();
        $body = $this->send('delete', static::INSTANCE_INSTALLS_PATH . '/' . static::encodePathSegment($extension));
        $this->forgetInstanceState();

        return [
            'install'     => is_array($body['install'] ?? null) ? $body['install'] : null,
            'deactivated' => is_numeric($body['deactivated'] ?? null) ? (int) $body['deactivated'] : 0,
        ];
    }

    /**
     * Call the registry once, uncached, and report whether it answered.
     *
     * Also checks the instance token against `/v1/instances/me` when one is set.
     *
     * @return array{ok: bool, latency_ms: int, status: int|null, extension_count: int|null, host: string, error: string|null, api_version: string, instance: array<string, mixed>|null}
     */
    public function testConnection(): array
    {
        $started  = hrtime(true);
        $status   = null;
        $count    = null;
        $ok       = false;
        $error    = null;
        $instance = null;

        try {
            $response = $this->request()->get(static::CATALOG_EXTENSIONS_PATH, ['limit' => 1]);
            $status   = $response->status();
            $ok       = $response->successful();

            if ($ok) {
                $payload = $response->json();
                $total   = is_array($payload) ? Arr::get($payload, 'meta.total') : null;
                $count   = is_numeric($total) ? (int) $total : count(static::extractList($payload));
            } else {
                $error = 'Registry responded with HTTP ' . $status . '.';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        if ($ok && $this->hasToken()) {
            $instance = $this->testInstanceToken();
        }

        return [
            'ok'              => $ok,
            'latency_ms'      => (int) round((hrtime(true) - $started) / 1_000_000),
            'status'          => $status,
            'extension_count' => $count,
            'host'            => $this->host,
            'error'           => $error,
            'api_version'     => RegistryConfig::V2,
            'instance'        => $instance,
        ];
    }

    /**
     * Forget every cached response for the configured host, including the last-good fallback.
     */
    public static function flush(): void
    {
        $index = Cache::get(static::indexKey(), []);
        foreach (static::stringList($index) as $key) {
            Cache::forget($key);
        }

        Cache::forget(static::indexKey());
        Cache::forget(static::failuresKey());
        static::$companyNames = [];
        Cache::forget(static::circuitKey());
    }

    /**
     * Forget cached `me` and `updates` responses so the next read reflects an install or uninstall.
     */
    public function forgetInstanceState(): void
    {
        foreach ([static::INSTANCE_PATH, static::INSTANCE_UPDATES_PATH] as $path) {
            foreach ([[], ['channel' => 'beta'], ['channel' => 'stable']] as $query) {
                Cache::forget($this->cacheKey($path, $query));
            }
        }
    }

    /**
     * Whether the circuit breaker is currently open (registry not being contacted).
     */
    public static function isCircuitOpen(): bool
    {
        return Cache::has(static::circuitKey());
    }

    /**
     * Build the pending request with base URL, auth and timeouts applied.
     */
    protected function request(bool $withToken = true): PendingRequest
    {
        $request = Http::baseUrl($this->host)
            ->acceptJson()
            ->timeout(static::TIMEOUT_SECONDS)
            ->connectTimeout(5)
            ->withHeaders(['User-Agent' => 'fleetbase-registry-bridge']);

        if ($withToken && $this->token) {
            $request = $request->withToken($this->token);

            if ($company = $this->company()) {
                $request = $request->withHeaders(array_filter([
                    static::TENANT_HEADER      => $company,
                    static::TENANT_NAME_HEADER => ($name = static::companyName($company)) !== null ? rawurlencode($name) : null,
                ]));
            }
        }

        return $request;
    }

    /**
     * Send an uncached write to the registry and decode the response.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     *
     * @throws RegistryUnavailableException|RegistryServiceException
     */
    protected function send(string $method, string $path, array $payload = [], bool $withToken = true): array
    {
        try {
            $request  = $this->request($withToken)->asJson();
            $response = match ($method) {
                'delete' => $request->delete($path, $payload),
                'get'    => $request->get($path, $payload),
                default  => $request->post($path, $payload),
            };
        } catch (\Throwable $e) {
            throw new RegistryUnavailableException('Unable to reach the extensions registry at ' . $this->host . ': ' . $e->getMessage(), 0, $e);
        }

        $body = $this->payloadFromResponse($response);

        return is_array($body) ? $body : [];
    }

    /**
     * GET a path through the cache and circuit breaker.
     *
     * @param array<string, mixed> $query
     *
     * @throws RegistryUnavailableException|RegistryServiceException
     */
    protected function cachedGet(string $path, array $query = [], ?int $ttlSeconds = null): mixed
    {
        $cacheKey    = $this->cacheKey($path, $query);
        $lastGoodKey = $cacheKey . ':last-good';

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        if (static::isCircuitOpen()) {
            return $this->fallback($lastGoodKey, 'Registry circuit is open after repeated failures.');
        }

        try {
            $response = $this->request()->get($path, $query);
            $payload  = $this->payloadFromResponse($response);
        } catch (RegistryServiceException $e) {
            // The registry answered; a refused request is not an outage
            if ($e->status() < 500) {
                throw $e;
            }

            $this->recordFailure($e);

            return $this->fallback($lastGoodKey, $e->getMessage());
        } catch (\Throwable $e) {
            $this->recordFailure($e);

            return $this->fallback($lastGoodKey, $e->getMessage());
        }

        Cache::put($cacheKey, $payload, $ttlSeconds ?? static::CACHE_TTL_MINUTES * 60);
        Cache::forever($lastGoodKey, $payload);
        $this->rememberKeys([$cacheKey, $lastGoodKey]);
        Cache::forget(static::failuresKey());

        return $payload;
    }

    /**
     * Decode a successful response or throw a `RegistryServiceException` built from the error envelope.
     *
     * @throws RegistryServiceException
     */
    protected function payloadFromResponse(Response $response): mixed
    {
        if ($response->failed()) {
            throw RegistryServiceException::fromResponse($response->status(), $response->json());
        }

        return $response->json();
    }

    /**
     * Serve the last good payload for a key, or give up.
     *
     * @throws RegistryUnavailableException
     */
    protected function fallback(string $lastGoodKey, string $reason): mixed
    {
        $lastGood = Cache::get($lastGoodKey);
        if ($lastGood !== null) {
            Log::warning('Registry unreachable, serving cached catalog.', ['host' => $this->host, 'reason' => $reason]);

            return $lastGood;
        }

        throw new RegistryUnavailableException('Unable to reach the extensions registry at ' . $this->host . ': ' . $reason);
    }

    /**
     * Count a failure and open the circuit once the threshold is hit.
     */
    protected function recordFailure(\Throwable $e): void
    {
        $failures = (int) Cache::get(static::failuresKey(), 0) + 1;
        Cache::put(static::failuresKey(), $failures, static::CIRCUIT_OPEN_SECONDS * 5);

        Log::warning('Registry request failed.', ['host' => $this->host, 'failures' => $failures, 'error' => $e->getMessage()]);

        if ($failures >= static::CIRCUIT_FAILURE_THRESHOLD) {
            Cache::put(static::circuitKey(), date(DATE_ATOM), static::CIRCUIT_OPEN_SECONDS);
            Cache::forget(static::failuresKey());
        }
    }

    /**
     * Check the instance token against `/v1/instances/me` without touching the cache.
     *
     * @return array{ok: bool, status: int|null, id: string|null, name: string|null, organization: string|null, error: string|null}
     */
    protected function testInstanceToken(): array
    {
        try {
            $response = $this->request()->get(static::INSTANCE_PATH);
            $payload  = $response->json();
            $payload  = is_array($payload) ? $payload : [];
            $string   = fn (string $key) => is_string(Arr::get($payload, $key)) ? Arr::get($payload, $key) : null;

            return [
                'ok'           => $response->successful(),
                'status'       => $response->status(),
                'id'           => $string('instance.id'),
                'name'         => $string('instance.name'),
                'organization' => $string('instance.organization.name'),
                'error'        => $response->successful() ? null : RegistryServiceException::fromResponse($response->status(), $payload)->getMessage(),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'id' => null, 'name' => null, 'organization' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @throws RegistryServiceException
     */
    protected function requireToken(): void
    {
        if (!$this->hasToken()) {
            throw new RegistryServiceException('This instance is not registered with the registry service. Register it from the Registry Configuration page.', 401);
        }
    }

    /**
     * Track cache keys so `flush()` can clear them without cache tags.
     *
     * @param array<int, string> $keys
     */
    protected function rememberKeys(array $keys): void
    {
        $index = Cache::get(static::indexKey(), []);
        $index = array_values(array_unique(array_merge(static::stringList($index), $keys)));

        Cache::forever(static::indexKey(), $index);
    }

    /**
     * @param array<string, mixed> $query
     */
    protected function cacheKey(string $path, array $query): string
    {
        ksort($query);

        // Instance state differs per company on the cloud instance; the public catalog does not
        $company = str_starts_with($path, 'v1/instances/') ? ($this->company() ?? '') : '';

        return static::CACHE_PREFIX . ':' . md5($this->host . '|' . ($this->token ?? '') . '|' . $company . '|' . $path . '|' . json_encode($query));
    }

    /**
     * How to look up a company's name (the service provider uses the companies table).
     *
     * @param (\Closure(string): ?string)|null $resolver
     */
    public static function resolveCompanyNamesUsing(?\Closure $resolver): void
    {
        static::$companyNameResolver = $resolver;
        static::$companyNames        = [];
    }

    /**
     * The company's name, sent so the service can name the organization of a company it has not seen yet.
     */
    protected static function companyName(string $company): ?string
    {
        if (static::$companyNameResolver === null) {
            return null;
        }

        if (!array_key_exists($company, static::$companyNames)) {
            try {
                $name = (static::$companyNameResolver)($company);
            } catch (\Throwable $e) {
                $name = null;
            }

            static::$companyNames[$company] = is_string($name) && trim($name) !== '' ? trim($name) : null;
        }

        return static::$companyNames[$company];
    }

    /**
     * Normalize a cached value into a list of strings.
     *
     * @return array<int, string>
     */
    protected static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    protected static function indexKey(): string
    {
        return static::CACHE_PREFIX . ':keys';
    }

    protected static function failuresKey(): string
    {
        return static::CACHE_PREFIX . ':failures';
    }

    protected static function circuitKey(): string
    {
        return static::CACHE_PREFIX . ':circuit-open';
    }

    protected static function normalizeHost(string $host): string
    {
        return RegistryConfig::normalizeUrl($host);
    }

    /**
     * Encode a path segment but keep the `/` of a scoped npm name, which the service routes accept as-is.
     */
    protected static function encodePathSegment(string $segment): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $segment)));
    }

    /**
     * Unwrap a `{data: {...}}` single resource; the service returns resources unwrapped.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected static function unwrap(array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data']) && !array_is_list($payload['data'])) {
            return $payload['data'];
        }

        return $payload;
    }

    /**
     * Catalog endpoints return a bare JSON array (v1) or `{data: [], meta: {}}` (v2).
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function extractList(mixed $payload): array
    {
        if (is_array($payload) && array_key_exists('data', $payload) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        if (!is_array($payload)) {
            return [];
        }

        return array_values(array_filter($payload, 'is_array'));
    }
}
