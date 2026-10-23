<?php

namespace Fleetbase\RegistryBridge\Support;

use Fleetbase\Models\Setting;
use Illuminate\Container\Container;
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
 * @phpstan-consistent-constructor
 */
class RegistrySettings
{
    /**
     * Container key marking the settings as applied for the current request or job.
     */
    public const APPLIED = 'registry-bridge.registry.settings-applied';

    /**
     * Setting key => `registry-bridge.registry` config key.
     */
    public const KEYS = [
        InstanceRegistration::SETTING_SERVICE_URL    => 'service_url',
        InstanceRegistration::SETTING_INSTANCE_TOKEN => 'instance_token',
        InstanceRegistration::SETTING_INSTANCE       => 'instance',
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
                'instance_token' => InstanceRegistration::decryptToken($value, $encrypter),
                'instance'       => is_array($value) && $value !== [] ? $value : null,
                default          => is_string($value) && trim($value) !== '' ? $value : null,
            };

            $values[$key] = $value ?? ($defaults[$key] ?? null);
        }

        return $values;
    }

    /**
     * Stored values of the registry settings (setting key => value), or null when the settings table cannot be read.
     *
     * @return array<string, mixed>|null
     */
    protected function stored(): ?array
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
