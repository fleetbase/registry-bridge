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
