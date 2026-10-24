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
        'host' => env('REGISTRY_HOST', 'https://registry.fleetbase.io'),
        'token' => env('REGISTRY_TOKEN', env('REGISTRY_AUTH_TOKEN')),
        // When false (the default) this instance browses the central Fleetbase registry catalog
        // instead of its own `registry_extensions` tables. Set to true on an instance that runs
        // its own registry and publishes extensions locally.
        'self_hosted' => Utils::castBoolean(env('REGISTRY_SELF_HOSTED', false)),
        // Registry Service (v2). When a service URL is set the catalog, installs and updates go through it;
        // without one the cloud `~registry/v1/extensions` endpoint on `host` stays the fallback. Pin
        // `api_version` to `v1` to keep the fallback while a service URL is configured.
        'service_url' => env('REGISTRY_SERVICE_URL'),
        'api_version' => env('REGISTRY_API_VERSION'),
        // Issued by the service when an admin registers this instance; stored encrypted in settings.
        'instance_token' => env('REGISTRY_INSTANCE_TOKEN'),
        // `self_hosted` or `cloud`; defaults to `cloud` when `self_hosted` (this instance is the v1 registry) is true.
        'instance_kind' => env('REGISTRY_INSTANCE_KIND'),
        'instance' => [],
        // Seconds the stored registry settings stay in the application cache between requests; writing one of
        // them forgets the cache at once. 0 reads the settings table on every request.
        'settings_cache_ttl' => env('REGISTRY_SETTINGS_CACHE_TTL', 30),
    ],
    'stripe' => [
        'key' => env('STRIPE_KEY', env('STRIPE_API_KEY')),
        'secret' => env('STRIPE_SECRET', env('STRIPE_API_SECRET')),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],
    'extensions' => [
        'preinstalled' => Utils::castBoolean(env('REGISTRY_PREINSTALLED_EXTENSIONS', false)),
        'protected_prefixes' => explode(',', env('REGISTRY_PROTECTED_PREFIXES', '@fleetbase,fleetbase,@flb,@fleetbase-extension,@flb-extension'))
    ],
    'facilitator_fee' => env('REGISTRY_FACILITATOR_FEE', 10)
];
