<?php

namespace Fleetbase\RegistryBridge\Support\Installer\Contracts;

/**
 * Credentials the package managers need, as environment variables for one
 * process. Nothing is written to auth.json or .npmrc.
 */
interface PackageCredentials
{
    /**
     * Variables to add to the environment of the given command (argv).
     *
     * @param array<int, string> $command
     *
     * @return array<string, string>
     */
    public function environment(array $command): array;
}
