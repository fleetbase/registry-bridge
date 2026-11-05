<?php

namespace Fleetbase\RegistryBridge\Support\Installer\Contracts;

use Fleetbase\RegistryBridge\Support\Installer\ExtensionRef;
use Fleetbase\RegistryBridge\Support\Installer\Release;
use Fleetbase\RegistryBridge\Support\Installer\ReleaseNotFoundException;

/**
 * Where releases come from. Today: the extension's current bundle in the local
 * registry tables (LocalBundleReleaseSource). Later: the Registry Service
 * instance API, which also answers the licence question.
 */
interface ReleaseSource
{
    /**
     * Look up an extension by public id, uuid, slug or package name.
     */
    public function find(string $extension): ?ExtensionRef;

    /**
     * Resolve the release to install. A null version means the current one.
     *
     * @throws ReleaseNotFoundException
     */
    public function resolve(ExtensionRef $extension, ?string $version = null): Release;
}
