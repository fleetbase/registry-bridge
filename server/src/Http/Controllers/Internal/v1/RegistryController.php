<?php

namespace Fleetbase\RegistryBridge\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\RegistryBridge\Exceptions\RegistryException;
use Fleetbase\RegistryBridge\Models\RegistryServiceInstall;
use Fleetbase\RegistryBridge\Support\RegistryClient;
use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Fleetbase\RegistryBridge\Support\ServiceCatalog;
use Illuminate\Http\Request;

class RegistryController extends Controller
{
    /**
     * Categories of the Registry Service catalog with their published listing counts.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function categories()
    {
        try {
            return response()->json(['categories' => ServiceCatalog::categories(RegistryClient::fromConfig(RegistryConfig::current()))]);
        } catch (RegistryException $e) {
            return response()->error($e->getMessage(), 503);
        }
    }

    /**
     * Engines installed for the current company (`{name, version}`), read by the console to load them.
     *
     * @return \Illuminate\Http\JsonResponse|array<int, mixed>
     */
    public function getInstalledEngines(Request $request)
    {
        if (!$request->session()->has('company')) {
            return [];
        }

        $engines = RegistryServiceInstall::where('company_uuid', session('company'))
            ->whereNotNull('npm_package')
            ->get()
            ->map(fn ($install) => ['name' => $install->npm_package, 'version' => $install->version])
            ->values();

        return response()->json($engines);
    }

    /**
     * Whether an engine (`engine`, the npm package name) is installed for the current company.
     *
     * @return array{installed: bool}
     */
    public function getEngineInstallStatus(Request $request)
    {
        $engine = $request->input('engine');
        if (!$request->session()->has('company') || !$engine) {
            return ['installed' => false];
        }

        return ['installed' => RegistryServiceInstall::where(['company_uuid' => session('company'), 'npm_package' => $engine])->exists()];
    }
}
