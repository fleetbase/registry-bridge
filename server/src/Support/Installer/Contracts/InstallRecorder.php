<?php

namespace Fleetbase\RegistryBridge\Support\Installer\Contracts;

use Fleetbase\RegistryBridge\Support\Installer\ExtensionRef;
use Fleetbase\RegistryBridge\Support\Installer\Release;

/**
 * Reads and writes the per-company install records.
 */
interface InstallRecorder
{
    /**
     * The company an install is for. Returns the given company when it exists,
     * the only company when none is given and the instance has exactly one,
     * and null otherwise.
     */
    public function resolveCompany(?string $company): ?string;

    public function isInstalled(string $company, ExtensionRef $extension): bool;

    /**
     * Whether another company on this instance still has the extension, in
     * which case an uninstall keeps the packages.
     */
    public function installedElsewhere(string $company, ExtensionRef $extension): bool;

    /**
     * The version recorded for the company's install, if any.
     */
    public function installedVersion(string $company, ExtensionRef $extension): ?string;

    /**
     * @param array<string, mixed> $meta
     */
    public function record(string $company, ExtensionRef $extension, Release $release, array $meta = []): void;

    public function remove(string $company, ExtensionRef $extension): void;
}
