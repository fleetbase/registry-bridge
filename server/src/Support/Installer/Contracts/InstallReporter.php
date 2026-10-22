<?php

namespace Fleetbase\RegistryBridge\Support\Installer\Contracts;

use Fleetbase\RegistryBridge\Support\Installer\ExtensionRef;
use Fleetbase\RegistryBridge\Support\Installer\Release;

/**
 * Tells the registry a release came from what happened to it on this instance.
 *
 * For the Registry Service this is the instance API: `starting()` reports
 * `installing`, which is also where the service checks the licence and the
 * extension's hosting and claims a seat, so it may refuse the install before
 * anything is changed. `finished()` and `failed()` are best effort and must
 * not throw. Releases from this instance's own tables have nobody to tell.
 */
interface InstallReporter
{
    /**
     * Called once the release is resolved, before anything changes (install and update only).
     *
     * @throws \Throwable to refuse the install; the message becomes the job's error
     */
    public function starting(string $action, ExtensionRef $extension, Release $release, string $company, ?string $user = null): void;

    /**
     * Called after the job succeeded. For an uninstall, `$lastOnInstance` is false
     * while another company on this instance still has the extension.
     */
    public function finished(string $action, ExtensionRef $extension, Release $release, string $company, ?string $user = null, bool $lastOnInstance = true): void;

    /**
     * Called after a job that `starting()` accepted failed and was rolled back.
     */
    public function failed(string $action, ExtensionRef $extension, Release $release, string $company, string $message, ?string $user = null): void;
}
