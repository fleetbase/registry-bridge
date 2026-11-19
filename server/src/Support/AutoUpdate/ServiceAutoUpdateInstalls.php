<?php

namespace Fleetbase\RegistryBridge\Support\AutoUpdate;

use Fleetbase\RegistryBridge\Models\RegistryServiceInstall;
use Fleetbase\RegistryBridge\Support\AutoUpdate\Contracts\AutoUpdateInstalls;

/**
 * Policies on `registry_service_installs.auto_update`; the last attempt lives in `meta.auto_update`.
 */
class ServiceAutoUpdateInstalls implements AutoUpdateInstalls
{
    public function withPolicy(): array
    {
        return RegistryServiceInstall::query()
            ->whereIn('auto_update', [UpdatePolicy::PATCH, UpdatePolicy::MINOR])
            ->whereNotNull('company_uuid')
            ->orderBy('id')
            ->get()
            ->map(fn (RegistryServiceInstall $install) => [
                'company'      => (string) $install->company_uuid,
                'extension_id' => (string) $install->extension_id,
                'name'         => (string) ($install->name ?: $install->slug ?: $install->extension_id),
                'version'      => $install->version,
                'policy'       => UpdatePolicy::normalize($install->auto_update),
                'last'         => is_array($install->meta['auto_update'] ?? null) ? $install->meta['auto_update'] : null,
            ])
            ->all();
    }

    public function remember(string $company, string $extensionId, array $state): void
    {
        $install = RegistryServiceInstall::where(['company_uuid' => $company, 'extension_id' => $extensionId])->first();
        if ($install === null) {
            return;
        }

        $install->meta = array_merge((array) ($install->meta ?? []), ['auto_update' => $state]);
        $install->save();
    }
}
