<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

use Fleetbase\RegistryBridge\Support\Installer\Contracts\PackageCredentials;
use Fleetbase\RegistryBridge\Support\PackageAuth;

/**
 * The instance token for the Registry Service package endpoints, per process:
 * `COMPOSER_AUTH` for Composer, and for pnpm and npm the npm credential
 * variables plus the placeholder-only user config (see PackageAuth). Applied to
 * every Composer and pnpm command, rollbacks included, since a restored lock
 * file can point at packages served by the service.
 */
class ServicePackageCredentials implements PackageCredentials
{
    /**
     * @param string      $composerBinary the configured Composer binary (InstallerConfig)
     * @param string      $pnpmBinary     the configured pnpm binary (InstallerConfig)
     * @param string|null $composerAuth   COMPOSER_AUTH already in the environment, kept for other hosts
     */
    public function __construct(
        private readonly PackageAuth $auth,
        private readonly string $composerBinary = 'composer',
        private readonly string $pnpmBinary = 'pnpm',
        private readonly ?string $composerAuth = null,
    ) {
    }

    public function environment(array $command): array
    {
        $program = $command[0] ?? '';

        if ($program === $this->composerBinary || basename($program) === 'composer') {
            return $this->auth->composerEnv($this->composerAuth);
        }

        if ($program === $this->pnpmBinary || in_array(basename($program), ['pnpm', 'npm'], true)) {
            return $this->auth->npmEnv();
        }

        return [];
    }
}
