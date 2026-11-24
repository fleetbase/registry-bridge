<?php

namespace Fleetbase\RegistryBridge\Tests\Installer\Fakes;

use Fleetbase\RegistryBridge\Support\Installer\Contracts\InstallRecorder;
use Fleetbase\RegistryBridge\Support\Installer\ExtensionRef;
use Fleetbase\RegistryBridge\Support\Installer\Release;

class FakeInstallRecorder implements InstallRecorder
{
    /** @var array<string, array<string, array<string, mixed>>> meta by company and extension uuid */
    public array $installs = [];

    public bool $failOnRecord = false;

    /**
     * @param array<int, string> $companies
     */
    public function __construct(public array $companies = ['company-1'])
    {
    }

    public function resolveCompany(?string $company): ?string
    {
        if ($company !== null) {
            return in_array($company, $this->companies, true) ? $company : null;
        }

        return count($this->companies) === 1 ? $this->companies[0] : null;
    }

    public function isInstalled(string $company, ExtensionRef $extension): bool
    {
        return isset($this->installs[$company][$extension->uuid]);
    }

    public function installedElsewhere(string $company, ExtensionRef $extension): bool
    {
        foreach ($this->installs as $other => $extensions) {
            if ($other !== $company && isset($extensions[$extension->uuid])) {
                return true;
            }
        }

        return false;
    }

    public function installedVersion(string $company, ExtensionRef $extension): ?string
    {
        return $this->installs[$company][$extension->uuid]['version'] ?? null;
    }

    public function record(string $company, ExtensionRef $extension, Release $release, array $meta = []): void
    {
        if ($this->failOnRecord) {
            throw new \RuntimeException('database is down');
        }
        $this->installs[$company][$extension->uuid] = $meta;
    }

    public function remove(string $company, ExtensionRef $extension): void
    {
        unset($this->installs[$company][$extension->uuid]);
    }
}
