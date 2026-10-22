<?php

namespace Fleetbase\RegistryBridge\Support;

use Fleetbase\RegistryBridge\Exceptions\RegistryUnavailableException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the central Fleetbase extensions registry.
 *
 * Reads the registry host and bearer token from `registry-bridge.registry.*`, caches successful
 * catalog responses for 15 minutes and keeps the last good payload indefinitely so a registry outage
 * degrades to stale data instead of an empty Explore page. After a few consecutive failures the
 * circuit opens and the registry is not contacted again until the cool-down has passed.
 */
class RegistryClient
{
    /** How long a successful catalog response is served from cache, in minutes. */
    public const CACHE_TTL_MINUTES = 15;

    /** Consecutive failures before the circuit opens. */
    public const CIRCUIT_FAILURE_THRESHOLD = 3;

    /** How long the circuit stays open before the registry is retried, in seconds. */
    public const CIRCUIT_OPEN_SECONDS = 60;

    /** Timeout for a single registry request, in seconds. */
    public const TIMEOUT_SECONDS = 10;

    /** Public catalog endpoint, relative to the registry host. */
    public const EXTENSIONS_PATH = '~registry/v1/extensions';

    protected const CACHE_PREFIX = 'registry-bridge:registry-client';

    protected string $host;

    protected ?string $token;

    public function __construct(?string $host = null, ?string $token = null)
    {
        $this->host  = static::normalizeHost($host ?: static::configuredHost());
        $this->token = $token !== null && $token !== '' ? $token : static::configuredToken();
    }

    /**
     * The registry host this client talks to.
     */
    public function host(): string
    {
        return $this->host;
    }

    /**
     * Registry host from config (settings override env).
     */
    public static function configuredHost(): string
    {
        $host = config('registry-bridge.registry.host');

        return is_string($host) && $host !== '' ? $host : 'https://registry.fleetbase.io';
    }

    /**
     * Registry bearer token from config (settings override env).
     */
    public static function configuredToken(): ?string
    {
        $token = config('registry-bridge.registry.token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Whether this instance runs its own registry and serves Explore from its local tables.
     */
    public static function isSelfHosted(): bool
    {
        return Utils::castBoolean(config('registry-bridge.registry.self_hosted', false)) === true;
    }

    /**
     * Whether Explore and categories should be read from the central registry.
     */
    public static function usesCentralCatalog(): bool
    {
        return !static::isSelfHosted();
    }

    /**
     * List the published extensions on the central registry (`GET /~registry/v1/extensions`).
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
     * Call the registry once, uncached, and report whether it answered.
     *
     * @return array{ok: bool, latency_ms: int, status: int|null, extension_count: int|null, host: string, error: string|null}
     */
    public function testConnection(): array
    {
        $started = hrtime(true);
        $status  = null;
        $count   = null;
        $ok      = false;
        $error   = null;

        try {
            $response = $this->request()->get(static::EXTENSIONS_PATH);
            $status   = $response->status();
            $ok       = $response->successful();

            if ($ok) {
                $count = count(static::extractList($response->json()));
            } else {
                $error = 'Registry responded with HTTP ' . $status . '.';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return [
            'ok'              => $ok,
            'latency_ms'      => (int) round((hrtime(true) - $started) / 1_000_000),
            'status'          => $status,
            'extension_count' => $count,
            'host'            => $this->host,
            'error'           => $error,
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
     * Whether the circuit breaker is currently open (registry not being contacted).
     */
    public static function isCircuitOpen(): bool
    {
        return Cache::has(static::circuitKey());
    }

    /**
     * Build the pending request with base URL, auth and timeouts applied.
     */
    protected function request(): PendingRequest
    {
        $request = Http::baseUrl($this->host)
            ->acceptJson()
            ->timeout(static::TIMEOUT_SECONDS)
            ->connectTimeout(5)
            ->withHeaders(['User-Agent' => 'fleetbase-registry-bridge']);

        if ($this->token) {
            $request = $request->withToken($this->token);
        }

        return $request;
    }

    /**
     * GET a path through the cache and circuit breaker.
     *
     * @param array<string, mixed> $query
     *
     * @throws RegistryUnavailableException
     */
    protected function cachedGet(string $path, array $query = []): mixed
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
        } catch (\Throwable $e) {
            $this->recordFailure($e);

            return $this->fallback($lastGoodKey, $e->getMessage());
        }

        Cache::put($cacheKey, $payload, now()->addMinutes(static::CACHE_TTL_MINUTES));
        Cache::forever($lastGoodKey, $payload);
        $this->rememberKeys([$cacheKey, $lastGoodKey]);
        Cache::forget(static::failuresKey());

        return $payload;
    }

    /**
     * Decode a successful response or throw for an HTTP error.
     *
     * @throws \Illuminate\Http\Client\RequestException
     */
    protected function payloadFromResponse(Response $response): mixed
    {
        $response->throw();

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
        Cache::put(static::failuresKey(), $failures, now()->addSeconds(static::CIRCUIT_OPEN_SECONDS * 5));

        Log::warning('Registry request failed.', ['host' => $this->host, 'failures' => $failures, 'error' => $e->getMessage()]);

        if ($failures >= static::CIRCUIT_FAILURE_THRESHOLD) {
            Cache::put(static::circuitKey(), now()->toIso8601String(), now()->addSeconds(static::CIRCUIT_OPEN_SECONDS));
            Cache::forget(static::failuresKey());
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

        return static::CACHE_PREFIX . ':' . md5($this->host . '|' . ($this->token ?? '') . '|' . $path . '|' . json_encode($query));
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
        $host = trim($host);
        if (!preg_match('/^https?:\/\//i', $host)) {
            $host = 'https://' . $host;
        }

        return rtrim($host, '/');
    }

    /**
     * The public catalog endpoint returns a bare JSON array; tolerate a wrapped `{data: []}` too.
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
