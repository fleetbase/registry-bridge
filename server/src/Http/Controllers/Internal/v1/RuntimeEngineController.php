<?php

namespace Fleetbase\RegistryBridge\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\RegistryBridge\Support\Installer\RuntimeEngineManifest;
use Illuminate\Http\JsonResponse;

class RuntimeEngineController extends Controller
{
    /**
     * The runtime engines installed on this instance (prebuilt engine
     * releases). Public: the console reads it at boot, before sign-in, to
     * build its route map (ember-core utils/runtime-engines.js).
     */
    public function index(RuntimeEngineManifest $manifest): JsonResponse
    {
        return new JsonResponse($manifest->toResponse(), 200, ['Cache-Control' => 'no-cache']);
    }
}
