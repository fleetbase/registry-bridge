<?php

namespace Fleetbase\RegistryBridge\Tests\Support;

use Fleetbase\RegistryBridge\Support\RegistrySettings;

/**
 * RegistrySettings that reads from an array instead of the settings table and counts the reads (the cache in front
 * of the table is the real one).
 */
class StoredRegistrySettings extends RegistrySettings
{
    /** @var array<string, mixed>|null setting key => stored value; null when the table cannot be read */
    public static ?array $stored = [];

    public static int $reads = 0;

    protected function query(): ?array
    {
        static::$reads++;

        return static::$stored;
    }
}
