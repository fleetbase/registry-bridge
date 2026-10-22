<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

use Fleetbase\RegistryBridge\Support\Installer\Contracts\InstallRecorder;

/**
 * Keeps install records where the extension came from: the local install
 * table for this instance's registry, `registry_service_installs` for the
 * Registry Service.
 */
class SourceRoutingInstallRecorder implements InstallRecorder
{
    /**
     * @param array<string, InstallRecorder> $recorders keyed by ExtensionRef source; `local` is the fallback and resolves companies
     */
    public function __construct(private readonly array $recorders)
    {
    }

    public function resolveCompany(?string $company): ?string
    {
        return $this->for(ExtensionRef::LOCAL)->resolveCompany($company);
    }

    public function isInstalled(string $company, ExtensionRef $extension): bool
    {
        return $this->for($extension->source)->isInstalled($company, $extension);
    }

    public function installedElsewhere(string $company, ExtensionRef $extension): bool
    {
        return $this->for($extension->source)->installedElsewhere($company, $extension);
    }

    public function installedVersion(string $company, ExtensionRef $extension): ?string
    {
        return $this->for($extension->source)->installedVersion($company, $extension);
    }

    public function record(string $company, ExtensionRef $extension, Release $release, array $meta = []): void
    {
        $this->for($extension->source)->record($company, $extension, $release, $meta);
    }

    public function remove(string $company, ExtensionRef $extension): void
    {
        $this->for($extension->source)->remove($company, $extension);
    }

    private function for(string $source): InstallRecorder
    {
        return $this->recorders[$source] ?? $this->recorders[ExtensionRef::LOCAL] ?? throw new \LogicException('No install recorder for ' . $source . ' extensions.');
    }
}
