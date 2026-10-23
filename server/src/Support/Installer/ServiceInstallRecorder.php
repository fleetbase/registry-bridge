<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

use Fleetbase\Models\Company;
use Fleetbase\RegistryBridge\Models\RegistryServiceInstall;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\InstallRecorder;

/**
 * Install records for Registry Service extensions (`registry_service_installs`),
 * which keep the engine loading and the Installed page working offline.
 */
class ServiceInstallRecorder implements InstallRecorder
{
    public function __construct(private readonly ?string $serviceUrl = null)
    {
    }

    /**
     * The company uuid for a uuid or public id, or the only company when none is given (single-tenant
     * self-hosted instances run the CLI without one).
     */
    public function resolveCompany(?string $company): ?string
    {
        if ($company !== null && $company !== '') {
            return Company::where('uuid', $company)->orWhere('public_id', $company)->value('uuid');
        }

        return Company::count() === 1 ? Company::value('uuid') : null;
    }

    public function isInstalled(string $company, ExtensionRef $extension): bool
    {
        return RegistryServiceInstall::where(['company_uuid' => $company, 'extension_id' => $extension->id])->exists();
    }

    public function installedElsewhere(string $company, ExtensionRef $extension): bool
    {
        return RegistryServiceInstall::where('extension_id', $extension->id)->where('company_uuid', '!=', $company)->exists();
    }

    public function installedVersion(string $company, ExtensionRef $extension): ?string
    {
        $version = RegistryServiceInstall::where(['company_uuid' => $company, 'extension_id' => $extension->id])->value('version');

        return $version === null ? null : (string) $version;
    }

    public function record(string $company, ExtensionRef $extension, Release $release, array $meta = []): void
    {
        $install = RegistryServiceInstall::firstOrNew(['company_uuid' => $company, 'extension_id' => $extension->id]);
        $install->fill([
            'name'             => $extension->name,
            'npm_package'      => $release->enginePackage,
            'composer_package' => $release->composerPackage,
            'version'          => $release->version,
            'service_url'      => $this->serviceUrl,
            'meta'             => array_merge((array) ($install->meta ?? []), $meta),
        ]);
        $install->save();
    }

    public function remove(string $company, ExtensionRef $extension): void
    {
        RegistryServiceInstall::where(['company_uuid' => $company, 'extension_id' => $extension->id])->delete();
    }
}
