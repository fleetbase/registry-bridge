<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

use Fleetbase\RegistryBridge\Support\Installer\Contracts\PackageCredentials;

/**
 * Package managers run with the inherited environment only.
 */
class NoPackageCredentials implements PackageCredentials
{
    public function environment(array $command): array
    {
        return [];
    }
}
