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
 * HTTP client for the Fleetbase extensions registry.
 *
 * @phpstan-consistent-constructor
 *
 * Talks to one of two registries (see `RegistryConfig`):
 *
 * - `v2`, the Registry Service: public catalog (`/v1/catalog/*`) and the instance API (`/v1/instances/*`),
 *   authenticated with the instance token issued at registration.
 * - `v1`, the cloud registry-bridge endpoint `~registry/v1/extensions`, the fallback when no service URL is set.
 *
 * Successful catalog responses are cached (15 minutes; instance state for one minute) and the last good
 * payload is kept indefinitely, so an outage degrades to stale data instead of an empty Explore page. After a
 * few consecutive failures the circuit opens and the registry is not contacted again until the cool-down has
 * passed. Client errors (4xx) never count as failures; they surface as `RegistryServiceException`.
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

    /** v1: public catalog endpoint, relative to the registry host. */
    public const EXTENSIONS_PATH = '~registry/v1/extensions';

    /** v2: Registry Service endpoints, relative to the service URL. */
    public const CATALOG_EXTENSIONS_PATH = 'v1/catalog/extensions';

    public const CATALOG_CATEGORIES_PATH = 'v1/catalog/categories';

    public const INSTANCE_REGISTER_PATH = 'v1/instances/register';

    public const INSTANCE_PATH = 'v1/instances/me';

    public const INSTANCE_UPDATES_PATH = 'v1/instances/me/updates';

    public const INSTANCE_INSTALLS_PATH = 'v1/instances/me/installs';

    protected const CACHE_PREFIX = 'registry-bridge:registry-client';

    protected string $host;

    protected ?string $token;

    protected string $apiVersion;

    /**
     * @param string|null         $host       base URL; defaults to the service URL (v2) or the registry host (v1)
     * @param string|null         $token      bearer token; defaults to the instance token (v2) or the registry token (v1)
     * @param string|null         $apiVersion `v1` or `v2`; defaults to the configured version
     * @param RegistryConfig|null $config     settings to resolve defaults from; defaults to the running config
     */
    public function __construct(?string $host = null, ?string $token = null, ?string $apiVersion = null, ?RegistryConfig $config = null)
    {
        $config           = $config ?? RegistryConfig::current();
        $this->apiVersion = in_array($apiVersion, [RegistryConfig::V1, RegistryConfig::V2], true) ? $apiVersion : $config->apiVersion();

        $usesService = $this->apiVersion === RegistryConfig::V2;
        $defaultHost = $usesService ? ($config->serviceUrl() ?? $config->host()) : $config->host();
        $this->host  = static::normalizeHost($host ?: $defaultHost);

        $defaultToken = $usesService ? $config->instanceToken() : $config->token();
        $this->token  = $token !== null && $token !== '' ? $token : $defaultToken;
    }

    /**
     * Client for the given settings.
     */
    public static function fromConfig(RegistryConfig $config): static
    {
        return new static(null, null, null, $config);
    }

    /**
     * The registry host this client talks to.
     */
    public function host(): string
    {
        return $this->host;
    }

    /**
     * `v1` (cloud registry-bridge) or `v2` (Registry Service).
     */
    public function apiVersion(): string
    {
        return $this->apiVersion;
    }

    /**
     * Whether this client talks to the Registry Service.
     */
    public function usesService(): bool
    {
        return $this->apiVersion === RegistryConfig::V2;
    }

    /**
     * Whether a bearer token is attached to requests.
     */
    public function hasToken(): bool
    {
        return $this->token !== null;
    }

    /**
     * Registry host from config (settings override env).
     */
    public static function configuredHost(): string
    {
        return RegistryConfig::current()->host();
    }

    /**
     * Registry bearer token from config (settings override env).
     */
    public static function configuredToken(): ?string
    {
        return RegistryConfig::current()->token();
    }

    /**
     * Whether this instance runs its own registry and serves Explore from its local tables.
     */
    public static function isSelfHosted(): bool
    {
        return RegistryConfig::current()->isSelfHostedRegistry();
    }

    /**
     * Whether Explore and categories should be read from a central registry: always with a Registry Service,
     * otherwise unless this instance is itself the (v1) registry.
     */
    public static function usesCentralCatalog(): bool
    {
        $config = RegistryConfig::current();

        return $config->usesService() || !$config->isSelfHostedRegistry();
    }

    /**
     * v1: list the published extensions on the cloud registry (`GET /~registry/v1/extensions`).
     *
     * Cached for 15 minutes per query. On failure the last good payload is returned; when there is
     * none a `RegistryUnavailableException` is thrown.
     *
     * @param array<string, mixed> $query
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RegistryUnavailableException
     */
    public function listPublicExtensions(array $query = []): array
    {
        $payload = $this->cachedGet(static::EXTENSIONS_PATH, $query);

        return static::extractList($payload);
    }

    /**
     * v2: one page of the service catalog (`GET /v1/catalog/extensions`).
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
     * v2: every published listing, walking the catalog pages.
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
     * v2: one full listing by slug or `ext_` id (`GET /v1/catalog/extensions/{slug}`), or null when it does not exist.
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
     * v2: categories with published extension counts (`GET /v1/catalog/categories`).
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
     * v2: this instance, its organization and the organization's licences (`GET /v1/instances/me`).
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
     * v2: installed releases against the newest compatible release (`GET /v1/instances/me/updates`).
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
     * v2: exchange a one-time registration code for an instance token (`POST /v1/instances/register`).
     *
     * @param array<string, mixed> $payload `code`, `name`, `kind`, and optional `host`, `console_version`, `api_version`
     *
     * @return array{token: string, token_type: string, instance: array<string, mixed>}
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
            'instance'   => is_array($body['instance'] ?? null) ? $body['instance'] : [],
        ];
    }

    /**
     * v2: report an install attempt (`POST /v1/instances/me/installs`).
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
     * v2: report an uninstall (`DELETE /v1/instances/me/installs/{extension}`). Scoped npm names work in the path.
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
     * In v2 also checks the instance token against `/v1/instances/me` when one is set.
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
            $response = $this->usesService()
                ? $this->request()->get(static::CATALOG_EXTENSIONS_PATH, ['limit' => 1])
                : $this->request()->get(static::EXTENSIONS_PATH);
            $status   = $response->status();
            $ok       = $response->successful();

            if ($ok) {
                $payload = $response->json();
                $total   = is_array($payload) ? Arr::get($payload, 'meta.total') : null;
                $count   = $this->usesService() && is_numeric($total) ? (int) $total : count(static::extractList($payload));
            } else {
                $error = 'Registry responded with HTTP ' . $status . '.';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        if ($ok && $this->usesService() && $this->hasToken()) {
            $instance = $this->testInstanceToken();
        }

        return [
            'ok'              => $ok,
            'latency_ms'      => (int) round((hrtime(true) - $started) / 1_000_000),
            'status'          => $status,
            'extension_count' => $count,
            'host'            => $this->host,
            'error'           => $error,
            'api_version'     => $this->apiVersion,
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
            $response = $method === 'delete' ? $request->delete($path, $payload) : $request->post($path, $payload);
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

        return static::CACHE_PREFIX . ':' . md5($this->apiVersion . '|' . $this->host . '|' . ($this->token ?? '') . '|' . $path . '|' . json_encode($query));
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
