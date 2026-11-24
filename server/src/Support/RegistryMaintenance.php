<?php

namespace Fleetbase\RegistryBridge\Support;

use Fleetbase\Models\Setting;
use Fleetbase\RegistryBridge\Exceptions\RegistryMaintenanceException;

/**
 * The cutover freeze in the console: while it is on, checkout, install, update and uninstall are refused with a
 * message (HTTP 503) and the Extensions pages show it as a banner. Browsing, Installed and Purchased keep working.
 *
 * Stored in the core-api `settings` table (`registry-bridge.registry.maintenance`) so it reaches every web and queue
 * worker on their next request or job (see `RegistrySettings`); `REGISTRY_MAINTENANCE=true` forces it on.
 */
class RegistryMaintenance
{
    public const SETTING = 'registry-bridge.registry.maintenance';

    /**
     * Turn the freeze on, with the message the console shows (default: `RegistryConfig::DEFAULT_MAINTENANCE_MESSAGE`).
     */
    public static function enable(?string $message = null): void
    {
        static::store(['enabled' => true, 'message' => $message !== null && trim($message) !== '' ? trim($message) : null, 'since' => date(DATE_ATOM)]);
    }

    public static function disable(): void
    {
        static::store(['enabled' => false]);
    }

    /**
     * Refuse a checkout, install, update or uninstall while the freeze is on.
     *
     * @throws RegistryMaintenanceException
     */
    public static function guard(?RegistryConfig $config = null): void
    {
        $maintenance = ($config ?? RegistryConfig::current())->maintenance();
        if ($maintenance['enabled']) {
            throw new RegistryMaintenanceException($maintenance['message']);
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    protected static function store(array $value): void
    {
        static::configure(static::SETTING, $value);
        config(['registry-bridge.registry.maintenance_window' => $value]);
        RegistrySettings::forgetCached();
    }

    /**
     * Write one setting (overridden in tests, which have no database).
     */
    protected static function configure(string $key, mixed $value): void
    {
        Setting::configure($key, $value);
    }
}
