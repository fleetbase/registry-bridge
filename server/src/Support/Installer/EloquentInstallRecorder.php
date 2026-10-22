<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

use Fleetbase\Models\Company;
use Fleetbase\RegistryBridge\Models\RegistryExtensionInstall;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\InstallRecorder;

class EloquentInstallRecorder implements InstallRecorder
{
    public function resolveCompany(?string $company): ?string
    {
        if ($company !== null && $company !== '') {
            return Company::where('uuid', $company)->orWhere('public_id', $company)->value('uuid');
        }

        return Company::count() === 1 ? Company::value('uuid') : null;
    }

    public function isInstalled(string $company, ExtensionRef $extension): bool
    {
        return RegistryExtensionInstall::where(['company_uuid' => $company, 'extension_uuid' => $extension->uuid])->exists();
    }

    public function installedElsewhere(string $company, ExtensionRef $extension): bool
    {
        return RegistryExtensionInstall::where('extension_uuid', $extension->uuid)->where('company_uuid', '!=', $company)->exists();
    }

    public function installedVersion(string $company, ExtensionRef $extension): ?string
    {
        $install = RegistryExtensionInstall::where(['company_uuid' => $company, 'extension_uuid' => $extension->uuid])->first();
        $version = $install ? data_get($install->meta, 'version') : null;

        return $version === null ? null : (string) $version;
    }

    public function record(string $company, ExtensionRef $extension, Release $release, array $meta = []): void
    {
        $install       = RegistryExtensionInstall::firstOrNew(['company_uuid' => $company, 'extension_uuid' => $extension->uuid]);
        $install->meta = array_merge((array) ($install->meta ?? []), $meta);
        $install->save();
    }

    public function remove(string $company, ExtensionRef $extension): void
    {
        RegistryExtensionInstall::where(['company_uuid' => $company, 'extension_uuid' => $extension->uuid])->delete();
    }
}
