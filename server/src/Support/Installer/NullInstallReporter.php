<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

use Fleetbase\RegistryBridge\Support\Installer\Contracts\InstallReporter;

/**
 * Reports nowhere: releases from this instance's own registry tables.
 */
class NullInstallReporter implements InstallReporter
{
    public function starting(string $action, ExtensionRef $extension, Release $release, string $company, ?string $user = null): void
    {
    }

    public function finished(string $action, ExtensionRef $extension, Release $release, string $company, ?string $user = null, bool $lastOnInstance = true): void
    {
    }

    public function failed(string $action, ExtensionRef $extension, Release $release, string $company, string $message, ?string $user = null): void
    {
    }
}
