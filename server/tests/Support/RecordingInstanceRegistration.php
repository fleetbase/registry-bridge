<?php

namespace Fleetbase\RegistryBridge\Tests\Support;

use Fleetbase\RegistryBridge\Support\InstanceRegistration;

/**
 * InstanceRegistration that records settings writes instead of touching the database.
 */
class RecordingInstanceRegistration extends InstanceRegistration
{
    /** @var array<string, mixed> */
    public static array $settings = [];

    protected static function configure(string $key, mixed $value): void
    {
        static::$settings[$key] = $value;
    }
}
