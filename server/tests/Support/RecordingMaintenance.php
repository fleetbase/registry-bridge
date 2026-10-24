<?php

namespace Fleetbase\RegistryBridge\Tests\Support;

use Fleetbase\RegistryBridge\Support\RegistryMaintenance;

/**
 * RegistryMaintenance that records settings writes instead of touching the database.
 */
class RecordingMaintenance extends RegistryMaintenance
{
    /** @var array<string, mixed> */
    public static array $settings = [];

    protected static function configure(string $key, mixed $value): void
    {
        static::$settings[$key] = $value;
    }
}
