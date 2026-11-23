<?php

namespace Fleetbase\RegistryBridge\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\RegistryBridge\Support\Installer\InstallerConfig;
use Fleetbase\RegistryBridge\Support\Installer\RuntimeEngineManifest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RuntimeEngineController extends Controller
{
    /** Content types for the files a prebuilt engine release holds; anything else is not served. */
    public const CONTENT_TYPES = [
        'js'    => 'application/javascript; charset=utf-8',
        'mjs'   => 'application/javascript; charset=utf-8',
        'css'   => 'text/css; charset=utf-8',
        'json'  => 'application/json; charset=utf-8',
        'map'   => 'application/json; charset=utf-8',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'eot'   => 'application/vnd.ms-fontobject',
        'txt'   => 'text/plain; charset=utf-8',
    ];

    /**
     * The runtime engines installed on this instance (prebuilt engine
     * releases). Public: the console reads it at boot, before sign-in, to
     * build its route map (ember-core utils/runtime-engines.js). When this API
     * serves the engines (no runtime_engines.base_url), each baseUrl points at
     * `files` below, on this API's own URL.
     */
    public function index(RuntimeEngineManifest $manifest, InstallerConfig $config): JsonResponse
    {
        $filesUrl = $config->serveRuntimeEngines ? url(config('registry-bridge.api.routing.prefix', '~registry') . '/v1/engines/files') : null;

        return new JsonResponse($manifest->toResponse($filesUrl), 200, ['Cache-Control' => 'no-cache']);
    }

    /**
     * GET ~registry/v1/engines/files/<name>/<version>/<file>: a file of an installed runtime engine, for consoles
     * that cannot see the runtime engines folder (Docker, a separate console host). Public like the manifest, and
     * only for the installed version of an installed engine; a version in the URL makes the files immutable.
     */
    public function file(RuntimeEngineManifest $manifest, InstallerConfig $config, string $path): BinaryFileResponse|Response
    {
        $file = $config->serveRuntimeEngines ? $manifest->servedFile($config->runtimeEnginesPath, $path) : null;
        $type = $file === null ? null : (self::CONTENT_TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null);
        if ($file === null || $type === null) {
            return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8', 'Access-Control-Allow-Origin' => '*']);
        }

        return new BinaryFileResponse($file, 200, [
            'Content-Type'                => $type,
            'Cache-Control'               => 'public, max-age=31536000, immutable',
            'Access-Control-Allow-Origin' => '*',
            'X-Content-Type-Options'      => 'nosniff',
        ], true, null, true, true);
    }
}
