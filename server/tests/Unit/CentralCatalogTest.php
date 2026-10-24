<?php

use Fleetbase\RegistryBridge\Support\CentralCatalog;

function remoteExtension(array $overrides = []): array
{
    return array_merge([
        'id'               => 'extension_abc123',
        'slug'             => 'fleet-ops',
        'name'             => 'Fleet-Ops',
        'subtitle'         => 'Fleet management',
        'description'      => 'Fleet & Transport Management Extension for Fleetbase',
        'icon_url'         => 'https://example.test/icon.svg',
        'tags'             => ['fleet'],
        'version'          => '0.6.73',
        'status'           => 'published',
        'payment_required' => false,
        'installs_count'   => 42,
        'publisher'        => ['name' => 'Fleetbase', 'slug' => 'fleetbase'],
        'category'         => ['id' => 'category_logistics', 'name' => 'Logistics', 'slug' => 'logistics', 'icon' => 'truck'],
        'current_bundle'   => [
            'version' => '0.6.73',
            'meta'    => [
                'package.json'  => ['name' => '@fleetbase/fleetops-engine'],
                'composer.json' => ['name' => 'fleetbase/fleetops-api'],
            ],
        ],
    ], $overrides);
}

test('transform maps a registry entry into the RegistryExtension resource shape', function () {
    $mapped = CentralCatalog::transform(remoteExtension(), [], 'https://registry.fleetbase.io');

    expect($mapped['id'])->toBe('extension_abc123')
        ->and($mapped['uuid'])->toBe('extension_abc123')
        ->and($mapped['public_id'])->toBe('extension_abc123')
        ->and($mapped['name'])->toBe('Fleet-Ops')
        ->and($mapped['category_name'])->toBe('Logistics')
        ->and($mapped['category_uuid'])->toBe('category_logistics')
        ->and($mapped['category']['slug'])->toBe('logistics')
        ->and($mapped['category']['for'])->toBe('extension_category')
        ->and($mapped['publisher_name'])->toBe('Fleetbase')
        ->and($mapped['current_bundle_version'])->toBe('0.6.73')
        ->and($mapped['installs_count'])->toBe(42)
        ->and($mapped['is_purchased'])->toBeFalse()
        ->and($mapped['is_author'])->toBeFalse()
        ->and($mapped['is_installable'])->toBeFalse()
        ->and($mapped['source'])->toBe('registry')
        ->and($mapped['meta']['registry_host'])->toBe('https://registry.fleetbase.io')
        ->and($mapped['meta']['packages'])->toBe(['@fleetbase/fleetops-engine', 'fleetbase/fleetops-api']);
});

test('is_installed is derived from local package names', function () {
    expect(CentralCatalog::transform(remoteExtension(), [])['is_installed'])->toBeFalse()
        ->and(CentralCatalog::transform(remoteExtension(), ['fleetbase/fleetops-api'])['is_installed'])->toBeTrue()
        ->and(CentralCatalog::transform(remoteExtension(), ['@fleetbase/fleetops-engine'])['is_installed'])->toBeTrue()
        ->and(CentralCatalog::transform(remoteExtension(), ['@fleetbase/other'])['is_installed'])->toBeFalse();
});

test('packageNames reads dotted manifest keys from bundle meta', function () {
    expect(CentralCatalog::packageNames(remoteExtension()))->toBe(['@fleetbase/fleetops-engine', 'fleetbase/fleetops-api'])
        ->and(CentralCatalog::packageNames(['current_bundle' => null]))->toBe([])
        ->and(CentralCatalog::packageNames(['package_name' => 'acme/thing']))->toBe(['acme/thing']);
});

test('transformCategory falls back to a slug derived id and ignores invalid input', function () {
    $category = CentralCatalog::transformCategory(['name' => 'Last Mile']);

    expect($category['id'])->toBe('category_last-mile')
        ->and($category['slug'])->toBe('last-mile')
        ->and($category['core_category'])->toBe(1)
        ->and(CentralCatalog::transformCategory(null))->toBeNull()
        ->and(CentralCatalog::transformCategory(['slug' => 'no-name']))->toBeNull();
});
