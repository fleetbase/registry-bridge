<?php

/**
 * -------------------------------------------
 * Fleetbase Core API Configuration
 * -------------------------------------------
 */

use Fleetbase\Support\Utils;

return [
    'api' => [
        'version' => '0.0.1',
        'routing' => [
            'prefix' => '~registry',
            'internal_prefix' => 'v1'
        ],
    ],
    'registry' => [
        // Registry Service: catalog, package endpoints and the instance API.
        'service_url' => env('REGISTRY_SERVICE_URL', 'https://registry.fleetbase.io'),
        // Issued by the service when an admin registers this instance; stored encrypted in settings.
        'instance_token' => env('REGISTRY_INSTANCE_TOKEN'),
        // `self_hosted` (default) or `cloud`; matched against each listing's hosting.
        'instance_kind' => env('REGISTRY_INSTANCE_KIND'),
        'instance' => [],
        // Publisher portal the console links to (the service's website). Defaults to the service URL.
        'publisher_url' => env('REGISTRY_PUBLISHER_URL'),
        // Purchases through the service checkout: `auto` (on for a registered instance unless the service
        // has no checkout endpoint), `true` or `false`.
        'checkout' => env('REGISTRY_SERVICE_CHECKOUT', 'auto'),
    ],
    'extensions' => [
        'preinstalled' => Utils::castBoolean(env('REGISTRY_PREINSTALLED_EXTENSIONS', false)),
        'protected_prefixes' => explode(',', env('REGISTRY_PROTECTED_PREFIXES', '@fleetbase,fleetbase,@flb,@fleetbase-extension,@flb-extension'))
    ],
    'installer' => [
        // Queue installs and return a job id (the console follows the job). False runs the job inside the request, e.g. where no queue worker runs.
        'async' => Utils::castBoolean(env('REGISTRY_INSTALLER_ASYNC', true)),
        'queue' => env('REGISTRY_INSTALLER_QUEUE', 'installer'),
        'connection' => env('REGISTRY_INSTALLER_CONNECTION'),
        // Job records, queue order and the instance lock. Must be shared by the web and queue processes and support locks; null uses the default store (file when that is the null store).
        'cache_store' => env('REGISTRY_INSTALLER_CACHE_STORE'),
        // Defaults: base_path(), fleetbase.console.path, storage_path('app/registry-installer').
        'api_path' => env('REGISTRY_INSTALLER_API_PATH'),
        'console_path' => env('REGISTRY_INSTALLER_CONSOLE_PATH'),
        'work_path' => env('REGISTRY_INSTALLER_WORK_PATH'),
        'runtime_engines' => [
            // Where prebuilt engine releases are unpacked, one folder per package; the console must serve it at base_url. Default: <console>/dist/engines-dist.
            'path' => env('REGISTRY_RUNTIME_ENGINES_PATH'),
            'base_url' => env('REGISTRY_RUNTIME_ENGINES_BASE_URL', '/engines-dist/'),
            // The manifest served at GET ~registry/v1/engines/runtime. Default: <path>/runtime-engines.json.
            'manifest' => env('REGISTRY_RUNTIME_ENGINES_MANIFEST'),
        ],
        'compat' => [
            // auto: ember-core's check-engine-compat when node and the script exist, else the PHP port. Or node, php.
            'driver' => env('REGISTRY_ENGINE_COMPAT_DRIVER', 'auto'),
            'policy' => env('REGISTRY_ENGINE_COMPAT_POLICY', 'minor'),
            // Default: <console>/dist/host-modules.json and <console>/node_modules/@fleetbase/ember-core/bin/check-engine-compat.js.
            'host_modules' => env('REGISTRY_HOST_MODULES_PATH'),
            'script' => env('REGISTRY_ENGINE_COMPAT_SCRIPT'),
        ],
        'binaries' => [
            'composer' => env('REGISTRY_COMPOSER_BINARY', 'composer'),
            'pnpm' => env('REGISTRY_PNPM_BINARY', 'pnpm'),
            'php' => env('REGISTRY_PHP_BINARY', 'php'),
            'node' => env('REGISTRY_NODE_BINARY', 'node'),
        ],
        // pnpm build --environment; default app.env.
        'build_environment' => env('REGISTRY_INSTALLER_BUILD_ENV'),
        // Artisan commands run after an install or uninstall, without `php artisan`.
        'cache_commands' => [['optimize:clear'], ['queue:restart']],
        'require_checksums' => Utils::castBoolean(env('REGISTRY_INSTALLER_REQUIRE_CHECKSUMS', false)),
        'timeout' => (int) env('REGISTRY_INSTALLER_TIMEOUT', 7200),
        'lock_seconds' => (int) env('REGISTRY_INSTALLER_LOCK_SECONDS', 10800),
        'stale_after' => (int) env('REGISTRY_INSTALLER_STALE_AFTER', 1800),
        'retry_delay' => 15,
        'job_ttl' => 604800,
        'log_lines' => 50,
    ],
];
