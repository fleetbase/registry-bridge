<?php

namespace Fleetbase\RegistryBridge\Support;

use Fleetbase\RegistryBridge\Exceptions\RegistryException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Log;

/**
 * The cloud console's API registering itself with the Registry Service as the one cloud instance, on boot
 * (`registry:init`), from the scheduler and with `registry:cloud-register`.
 *
 * It needs `REGISTRY_CLOUD_AUTO_REGISTER=true` and the platform secret the service shares
 * (`REGISTRY_CLOUD_PLATFORM_SECRET`). The request is signed instead of carrying a registration code:
 *
 *   X-Registry-Timestamp  unix seconds
 *   X-Registry-Nonce      random, single use
 *   X-Registry-Signature  `v1=` + hex HMAC-SHA256 of "{timestamp}.{nonce}.{body}" with the secret
 *
 * Idempotent across restarts and replicas: a stored token the service still accepts is kept (nothing is sent), and
 * only one process registers at a time (a cache lock; the others wait, then find the token it stored). When a token
 * is needed the service rotates the same instance (D17: the stored token, else the same organization and host), so
 * installs and licence seats stay on it. An unreachable service never triggers a registration.
 */
class CloudRegistration
{
    public const LOCK = 'registry-bridge:cloud-register';

    /** Seconds the lock is held at most, and how long another process waits for it. */
    public const LOCK_SECONDS = 60;

    public const WAIT_SECONDS = 30;

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_CURRENT = 'current';

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_ROTATED = 'rotated';

    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * Make sure this API holds a token the service accepts, registering when it does not.
     *
     * @param bool $force register even when the stored token is accepted (rotates it)
     *
     * @return array{status: string, message: string, summary: array<string, mixed>|null}
     *
     * @throws RegistryException when the service refuses the registration (a wrong secret, a clock skew)
     */
    public static function ensure(bool $force = false): array
    {
        $config = RegistryConfig::current();
        if (!$config->cloudAutoRegister()) {
            return static::result(static::STATUS_DISABLED, 'Cloud registration is off: set REGISTRY_CLOUD_AUTO_REGISTER=true and REGISTRY_CLOUD_PLATFORM_SECRET.');
        }

        if (!$force && ($result = static::check($config)) !== null) {
            return $result;
        }

        $cache = Container::getInstance()->make('cache');
        $store = ($cache instanceof CacheFactory ? $cache->store() : $cache)->getStore();
        if (!$store instanceof LockProvider) {
            Log::warning('registry-bridge: the cache store has no locks; cloud registration is not serialized across processes.');

            return static::registerUnlessCurrent($force);
        }

        return $store->lock(static::LOCK, static::LOCK_SECONDS)->block(static::WAIT_SECONDS, fn () => static::registerUnlessCurrent($force));
    }

    /**
     * Inside the lock: read the stored settings again (another process may have registered while this one waited),
     * then register unless that left a token the service accepts.
     *
     * @return array{status: string, message: string, summary: array<string, mixed>|null}
     */
    protected static function registerUnlessCurrent(bool $force): array
    {
        RegistrySettings::forgetCached();
        RegistrySettings::instance()->apply();
        $config = RegistryConfig::current();

        if (!$force && ($result = static::check($config)) !== null) {
            return $result;
        }

        $summary = static::register($config);
        $rotated = ($summary['rotated'] ?? false) === true;

        return static::result(
            $rotated ? static::STATUS_ROTATED : static::STATUS_REGISTERED,
            sprintf('%s %s (%s) with %s.', $rotated ? 'Re-registered' : 'Registered', $summary['name'] ?? 'the cloud instance', $summary['id'] ?? 'unknown id', $summary['service_url'] ?? 'the registry'),
            $summary
        );
    }

    /**
     * A result when no registration is needed (the stored token is accepted) or possible (the service cannot be
     * asked); null when this API should register.
     *
     * @return array{status: string, message: string, summary: array<string, mixed>|null}|null
     */
    protected static function check(RegistryConfig $config): ?array
    {
        $serviceUrl = $config->serviceUrl();
        if (!$config->isRegistered() || $serviceUrl === null || ($config->instance()['service_url'] ?? $serviceUrl) !== $serviceUrl) {
            return null;
        }

        return match (RegistryClient::fromConfig($config)->instanceTokenAccepted()) {
            true    => static::result(static::STATUS_CURRENT, 'Already registered: the stored instance token is accepted.', $config->instance()),
            false   => null,
            default => static::result(static::STATUS_UNAVAILABLE, 'The registry service could not check the instance token; kept it and will try again.', $config->instance()),
        };
    }

    /**
     * Send the signed registration and store the token.
     *
     * @return array<string, mixed> the stored instance summary
     *
     * @throws RegistryException
     */
    public static function register(RegistryConfig $config): array
    {
        $serviceUrl = $config->serviceUrl() ?? RegistryConfig::DEFAULT_SERVICE_URL;
        $body       = (string) json_encode(static::payload($config, $serviceUrl), JSON_UNESCAPED_SLASHES);
        $client     = new RegistryClient($serviceUrl, null, $config);

        $registration = $client->registerCloudInstance($body, static::signatureHeaders((string) $config->platformSecret(), $body));

        // Resolved from the container so tests can record the settings writes
        $container = Container::getInstance();
        $store     = $container->bound(InstanceRegistration::class) ? $container->make(InstanceRegistration::class) : new InstanceRegistration();

        return $store::store($registration, $serviceUrl);
    }

    /**
     * Body of `POST /v1/instances/register/cloud`: name, host (the app URL, which finds this deployment's instance
     * again), versions and the current token of an instance this service issued.
     *
     * @return array<string, string>
     */
    public static function payload(RegistryConfig $config, string $serviceUrl): array
    {
        $name = config('app.name');

        return array_filter([
            'name'            => is_string($name) && trim($name) !== '' ? trim($name) : 'Fleetbase Cloud',
            'host'            => is_string(config('app.url')) ? trim((string) config('app.url')) : null,
            'console_version' => InstanceRegistration::detectConsoleVersion(is_string(config('fleetbase.console.path')) ? config('fleetbase.console.path') : null),
            'api_version'     => InstanceRegistration::detectApiVersion(),
            'previous_token'  => InstanceRegistration::previousToken($config, $serviceUrl),
        ], fn ($value) => is_string($value) && $value !== '');
    }

    /**
     * The platform signature headers for a body, as the service checks them.
     *
     * @return array<string, string>
     */
    public static function signatureHeaders(string $secret, string $body, ?int $timestamp = null, ?string $nonce = null): array
    {
        $timestamp = (string) ($timestamp ?? time());
        $nonce ??= bin2hex(random_bytes(16));

        return [
            'X-Registry-Timestamp' => $timestamp,
            'X-Registry-Nonce'     => $nonce,
            'X-Registry-Signature' => 'v1=' . hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $secret),
        ];
    }

    /**
     * @param array<string, mixed>|null $summary
     *
     * @return array{status: string, message: string, summary: array<string, mixed>|null}
     */
    protected static function result(string $status, string $message, ?array $summary = null): array
    {
        return ['status' => $status, 'message' => $message, 'summary' => $summary !== [] ? $summary : null];
    }
}
