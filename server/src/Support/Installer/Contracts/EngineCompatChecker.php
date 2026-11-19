<?php

namespace Fleetbase\RegistryBridge\Support\Installer\Contracts;

use Fleetbase\RegistryBridge\Support\Installer\CompatResult;

/**
 * Checks a prebuilt engine release against the console build it would run in
 * (@fleetbase/ember-core's check-engine-compat).
 */
interface EngineCompatChecker
{
    /**
     * @param string $manifestPath    the release's manifest.json
     * @param string $hostModulesPath the console build's host-modules.json
     * @param string $policy          minor, exact or modules
     */
    public function check(string $manifestPath, string $hostModulesPath, string $policy = 'minor'): CompatResult;
}
