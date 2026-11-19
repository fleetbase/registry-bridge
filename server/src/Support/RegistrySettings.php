<?php

namespace Fleetbase\RegistryBridge\Support;

use Fleetbase\Models\Setting;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Encryption\StringEncrypter;

/**
 * Applies the registry settings stored in the core-api `settings` table to `config('registry-bridge.registry')`.
 *
 * The settings are read with one query at most once per request or queued job: `RegistryConfig::current()` calls
 * `ensureApplied()`, and the service provider calls `reset()` on Octane's `RequestReceived` and the queue's
 * `JobProcessing`, so a registration made by another process (`php artisan registry:register`) or another worker
 * (the admin endpoint) takes effect everywhere on the next request without `octane:reload`.
 *
 * A key with no stored value falls back to what the config file and environment gave it before the first apply.
 *
 * The stored values are kept in the application cache for `registry.settings_cache_ttl` seconds (30 by default, 0 to
 * turn it off), so a request costs a cache read rather than a query. Writing or deleting one of the settings forgets
 * that cache (`forgetCached()`, called from a `Setting` model listener and by `InstanceRegistration`), so with a shared
 * cache store (Redis, the default in the Docker stack) every worker sees a change on its next request; with a
 * per-container store a worker in another container sees it within the TTL.
 *
 * @phpstan-consistent-constructor
 */
class RegistrySettings
{
    /**
     * Container key marking the settings as applied for the current request or job.
     */
    public const APPLIED = 'registry-bridge.registry.settings-applied';

    /**
     * Cache key holding the stored settings between requests.
     */
    public const CACHE_KEY = 'registry-bridge:registry-settings';

    /**
     * Seconds the stored settings stay cached when `registry.settings_cache_ttl` is not set.
     */
    public const CACHE_TTL = 30;

    /**
     * Setting key => `registry-bridge.registry` config key.
     */
    public const KEYS = [
        InstanceRegistration::SETTING_SERVICE_URL    => 'service_url',
        InstanceRegistration::SETTING_INSTANCE_TOKEN => 'instance_token',
        InstanceRegistration::SETTING_INSTANCE       => 'instance',
        RegistryMaintenance::SETTING                 => 'maintenance_window',
    ];

    /**
     * Config values for the settings-backed keys before any setting was applied (file and environment).
     *
     * @var array<string, mixed>|null
     */
    protected static ?array $defaults = null;

    /**
     * The settings reader bound in the container (replaced in tests), else a new one.
     */
    public static function instance(): static
    {
        $container = Container::getInstance();

        return $container->bound(static::class) ? $container->make(static::class) : new static();
    }

    /**
     * Apply the stored settings unless they were already applied for this request or job.
     */
    public function ensureApplied(): void
    {
        $container = Container::getInstance();
        if ($container->bound(static::APPLIED)) {
            return;
        }

        $this->apply();
    }

    /**
     * Read the stored settings and merge them into config.
     */
    public function apply(): void
    {
        $this->markApplied();

        $stored = $this->stored();
        if ($stored === null) {
            return;
        }

        $container = Container::getInstance();
        $config    = $container->make('config');
        static::$defaults ??= $this->currentValues($config->get('registry-bridge.registry', []));

        $config->set('registry-bridge.registry', array_merge(
            (array) $config->get('registry-bridge.registry', []),
            static::merge(static::$defaults, $stored, $container->make('encrypter'))
        ));
    }

    /**
     * Treat the settings as applied for this request or job without reading them (no database to read from).
     */
    public function markApplied(): void
    {
        Container::getInstance()->instance(static::APPLIED, true);
    }

    /**
     * Make the next `ensureApplied()` read the settings again (a new Octane request or queued job).
     */
    public function reset(?Container $container = null): void
    {
        ($container ?? Container::getInstance())->forgetInstance(static::APPLIED);
    }

    /**
     * Config values for the settings-backed keys: each stored setting that is set, else its default. A stored
     * instance token is decrypted with the app key and dropped when it cannot be.
     *
     * @param array<string, mixed> $defaults config values before any setting was applied
     * @param array<string, mixed> $stored   setting key => stored value
     *
     * @return array<string, mixed> config key => value
     */
    public static function merge(array $defaults, array $stored, StringEncrypter $encrypter): array
    {
        $values = [];
        foreach (static::KEYS as $setting => $key) {
            $value = $stored[$setting] ?? null;

            $value = match ($key) {
                'instance_token'                 => InstanceRegistration::decryptToken($value, $encrypter),
                'instance', 'maintenance_window' => is_array($value) && $value !== [] ? $value : null,
                default                          => is_string($value) && trim($value) !== '' ? $value : null,
            };

            $values[$key] = $value ?? ($defaults[$key] ?? null);
        }

        return $values;
    }

    /**
     * Forget the cached settings so the next request in every worker reads them from the settings table.
     */
    public static function forgetCached(): void
    {
        $cache = static::cache();

        try {
            $cache?->forget(static::CACHE_KEY);
        } catch (\Throwable $e) {
            // An unreachable cache store holds nothing to forget
        }
    }

    /**
     * Forget the cached settings when one of the registry settings is written or deleted.
     */
    public static function settingChanged(?string $key): void
    {
        if (is_string($key) && array_key_exists($key, static::KEYS)) {
            static::forgetCached();
        }
    }

    /**
     * Stored values of the registry settings (setting key => value) from the cache, else the settings table; null
     * when the table cannot be read (never cached, so the next request tries again).
     *
     * @return array<string, mixed>|null
     */
    protected function stored(): ?array
    {
        $cache = static::cache();
        $ttl   = $this->cacheTtl();
        if ($cache === null || $ttl <= 0) {
            return $this->query();
        }

        try {
            $cached = $cache->get(static::CACHE_KEY);
        } catch (\Throwable $e) {
            return $this->query();
        }

        if (is_array($cached)) {
            return $cached;
        }

        $stored = $this->query();
        if ($stored !== null) {
            try {
                $cache->put(static::CACHE_KEY, $stored, $ttl);
            } catch (\Throwable $e) {
                // Serve this request from the table; the next one tries the cache again
            }
        }

        return $stored;
    }

    /**
     * Stored values of the registry settings read from the settings table, or null when it cannot be read.
     *
     * @return array<string, mixed>|null
     */
    protected function query(): ?array
    {
        try {
            return Setting::query()
                ->whereIn('key', array_keys(static::KEYS))
                ->get(['key', 'value'])
                ->mapWithKeys(fn ($setting) => [$setting->key => $setting->value])
                ->all();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Seconds to cache the stored settings (`registry.settings_cache_ttl`, else `CACHE_TTL`); 0 turns the cache off.
     */
    protected function cacheTtl(): int
    {
        $container = Container::getInstance();
        $ttl       = $container->bound('config') ? $container->make('config')->get('registry-bridge.registry.settings_cache_ttl') : null;

        return is_numeric($ttl) ? max(0, (int) $ttl) : static::CACHE_TTL;
    }

    /**
     * The application cache, or null when none is bound (some tests, early boot).
     */
    protected static function cache(): ?CacheRepository
    {
        $container = Container::getInstance();
        if (!$container->bound('cache')) {
            return null;
        }

        try {
            $cache = $container->make('cache');

            return $cache instanceof CacheFactory ? $cache->store() : ($cache instanceof CacheRepository ? $cache : null);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function currentValues(mixed $registry): array
    {
        $registry = is_array($registry) ? $registry : [];

        return array_intersect_key($registry, array_flip(static::KEYS));
    }

    /**
     * Forget the captured defaults (tests).
     */
    public static function flush(): void
    {
        static::$defaults = null;
    }
}
