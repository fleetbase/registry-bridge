<?php

use Fleetbase\RegistryBridge\Support\ServiceCatalog;

test('a release whose ranges this instance meets is ready to install', function () {
    $result = ServiceCatalog::compatibility(null, ['console' => '>=0.7.0', 'api' => '>=1.6.0'], ['console' => '0.8.1', 'api' => '2.0.4']);

    expect($result)->toBe([
        'verdict'    => 'ready',
        'reason'     => 'ok',
        'requires'   => ['console' => '>=0.7.0', 'api' => '>=1.6.0', 'extensions' => []],
        'current'    => ['console' => '0.8.1', 'api' => '2.0.4'],
        'missing'    => [],
        'mismatched' => [],
    ]);
});

test('a release for a newer Fleetbase needs an update first', function () {
    expect(ServiceCatalog::compatibility(null, ['api' => '^2.1'], ['api' => '2.0.4'])['reason'])->toBe('core_too_old')
        ->and(ServiceCatalog::compatibility(null, ['console' => '>=0.9.0'], ['console' => '0.8.1', 'api' => '2.0.4']))->toMatchArray(['verdict' => 'needs_update', 'reason' => 'console_too_old']);
});

test('a release made only for an older Fleetbase is not available', function () {
    expect(ServiceCatalog::compatibility(null, ['api' => '^1.6'], ['api' => 'v2.0.4']))->toMatchArray(['verdict' => 'unavailable', 'reason' => 'core_too_new'])
        ->and(ServiceCatalog::compatibility(null, ['console' => '<0.8'], ['console' => '0.8.1']))->toMatchArray(['verdict' => 'unavailable', 'reason' => 'console_too_new']);
});

test('hosting, a missing release or a missing registration make it unavailable whatever the versions', function () {
    foreach (['hosting', 'no_release', 'not_registered'] as $code) {
        expect(ServiceCatalog::compatibility($code, [], ['api' => '2.0.4']))->toMatchArray(['verdict' => 'unavailable', 'reason' => $code]);
    }

    // A licence to buy is the purchase flow, not a compatibility problem.
    expect(ServiceCatalog::compatibility('licence_required', ['api' => '>=1.0'], ['api' => '2.0.4'])['verdict'])->toBe('ready');
});

test('unknown versions and unreadable ranges are not held against the instance', function () {
    expect(ServiceCatalog::compatibility(null, ['console' => '>=0.9.0', 'api' => 'not a range'], ['api' => '2.0.4'])['verdict'])->toBe('ready')
        ->and(ServiceCatalog::compatibility(null, null, [])['verdict'])->toBe('ready');
});

test('an extension it needs that is not installed makes it unavailable, with what is missing', function () {
    $compat   = ['console' => '>=0.7.0', 'extensions' => ['@fleetbase/fleetops-engine' => '>=0.6', '@acme/route-tools-engine' => '^1.0']];
    $versions = ['console' => '0.8.1', 'extensions' => ['@fleetbase/fleetops-engine' => '0.6.4']];
    $result   = ServiceCatalog::compatibility(null, $compat, $versions);

    expect($result)->toMatchArray(['verdict' => 'unavailable', 'reason' => 'missing_extension'])
        ->and($result['missing'])->toBe([['package' => '@acme/route-tools-engine', 'name' => 'Route Tools', 'range' => '^1.0']])
        ->and($result['mismatched'])->toBe([])
        ->and($result['requires']['extensions'])->toBe([
            ['package' => '@fleetbase/fleetops-engine', 'name' => 'Fleet-Ops', 'range' => '>=0.6', 'installed' => '0.6.4', 'fit' => 'ok'],
            ['package' => '@acme/route-tools-engine', 'name' => 'Route Tools', 'range' => '^1.0', 'installed' => null, 'fit' => 'missing'],
        ]);

    // A catalog name wins over the humanized one.
    expect(ServiceCatalog::compatibility(null, $compat, $versions, ['@acme/route-tools-engine' => 'Acme Routes'])['missing'][0]['name'])->toBe('Acme Routes');
});

test('an extension it needs in an older version needs an update; a newer one than allowed is unavailable', function () {
    $older = ServiceCatalog::compatibility(null, ['extensions' => ['@fleetbase/fleetops-engine' => '>=0.6']], ['extensions' => ['@fleetbase/fleetops-engine' => '0.5.9']]);
    expect($older)->toMatchArray(['verdict' => 'needs_update', 'reason' => 'extension_too_old'])
        ->and($older['mismatched'])->toBe([['package' => '@fleetbase/fleetops-engine', 'name' => 'Fleet-Ops', 'range' => '>=0.6', 'installed' => '0.5.9', 'fit' => 'older']]);

    $newer = ServiceCatalog::compatibility(null, ['extensions' => ['@fleetbase/storefront-engine' => '<0.4']], ['extensions' => ['@fleetbase/storefront-engine' => '0.4.2']]);
    expect($newer)->toMatchArray(['verdict' => 'unavailable', 'reason' => 'extension_too_new'])
        ->and($newer['mismatched'][0])->toMatchArray(['package' => '@fleetbase/storefront-engine', 'name' => 'Storefront', 'installed' => '0.4.2', 'fit' => 'newer']);

    // A Fleetbase that is too old is reported before an extension that is too old.
    expect(ServiceCatalog::compatibility(null, ['console' => '>=0.9', 'extensions' => ['@fleetbase/fleetops-engine' => '>=0.6']], ['console' => '0.8.1', 'extensions' => ['@fleetbase/fleetops-engine' => '0.5.0']])['reason'])->toBe('console_too_old');
});

test('extensions are not held against an instance whose console state is unknown, or whose version of one is', function () {
    $compat = ['extensions' => ['@fleetbase/fleetops-engine' => '>=0.6']];

    expect(ServiceCatalog::compatibility(null, $compat, [])['verdict'])->toBe('ready')
        ->and(ServiceCatalog::compatibility(null, $compat, [])['requires']['extensions'][0]['fit'])->toBe('unknown')
        ->and(ServiceCatalog::compatibility(null, $compat, ['extensions' => ['@fleetbase/fleetops-engine' => null]])['verdict'])->toBe('ready')
        ->and(ServiceCatalog::compatibility('hosting', $compat, ['extensions' => []])['reason'])->toBe('hosting');
});

test('installed extensions come from host-modules.json versions, built-in engines and local installs', function () {
    expect(ServiceCatalog::installedExtensions(null))->toBeNull()
        ->and(ServiceCatalog::installedExtensions(['host_versions' => null, 'built_in' => []]))->toBeNull()
        ->and(ServiceCatalog::installedExtensions(
            ['host_versions' => ['@fleetbase/ember-ui' => '0.4.6', '@fleetbase/fleetops-engine' => '0.6.4'], 'built_in' => ['@fleetbase/fleetops-engine', '@fleetbase/storefront-engine']],
            ['@acme/route-tools-engine']
        ))->toBe([
            '@fleetbase/fleetops-engine'   => '0.6.4',
            '@fleetbase/storefront-engine' => null,
            '@acme/route-tools-engine'     => null,
            '@fleetbase/ember-ui'          => '0.4.6',
        ]);
});

test('other extensions are named for people', function () {
    expect(ServiceCatalog::extensionName('@fleetbase/fleetops-engine'))->toBe('Fleet-Ops')
        ->and(ServiceCatalog::extensionName('@fleetbase/customer-portal-engine'))->toBe('Customer Portal')
        ->and(ServiceCatalog::extensionName('@fleetbase/vroom-engine'))->toBe('VROOM')
        ->and(ServiceCatalog::extensionName('@fleetbase/ai-engine'))->toBe('AI')
        ->and(ServiceCatalog::extensionName('@acme/route-tools-engine'))->toBe('Route Tools')
        ->and(ServiceCatalog::extensionName('loconav'))->toBe('Loconav')
        ->and(ServiceCatalog::extensionName('@acme/route-tools-engine', 'Acme Routes'))->toBe('Acme Routes');
});
