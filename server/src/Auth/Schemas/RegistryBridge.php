<?php

namespace Fleetbase\RegistryBridge\Auth\Schemas;

/**
 * Permissions for the console's Extensions module: browsing, installing and buying from the Registry Service.
 *
 * Publishing, review and payouts live on the publisher website, so their resources (registry tokens, bundles,
 * analytics, payments) and the developer roles are gone.
 */
class RegistryBridge
{
    /**
     * The permission schema Name.
     */
    public string $name = 'registry-bridge';

    /**
     * The permission schema Polict Name.
     */
    public string $policyName = 'RegistryBridge';

    /**
     * Guards these permissions should apply to.
     */
    public array $guards = ['sanctum'];

    /**
     * The permission schema resources.
     */
    public array $resources = [
        [
            'name'    => 'extension',
            'actions' => ['uninstall', 'install', 'purchase'],
        ],
        [
            'name'           => 'extension-purchase',
            'actions'        => [],
            'remove_actions' => ['create', 'update', 'delete'],
        ],
        [
            'name'           => 'extension-install',
            'actions'        => [],
            'remove_actions' => ['create', 'update', 'delete'],
        ],
    ];

    /**
     * Policies provided by this schema.
     */
    public array $policies = [
        [
            'name'        => 'ExtensionOperator',
            'description' => 'Policy for browsing, installing and buying extensions.',
            'permissions' => [
                'see extension',
                'list extension',
                'view extension',
                'install extension',
                'uninstall extension',
                'purchase extension',
                'see extension-install',
                'list extension-install',
                'see extension-purchase',
                'list extension-purchase',
            ],
        ],
    ];

    /**
     * Roles provided by this schema.
     */
    public array $roles = [
        [
            'name'        => 'Extension Admin',
            'description' => 'Role for installing, updating, uninstalling and buying extensions.',
            'policies'    => [
                'ExtensionOperator',
            ],
        ],
    ];
}
