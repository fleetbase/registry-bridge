<?php

use Fleetbase\RegistryBridge\Support\ServiceCatalog;

test('a release whose ranges this instance meets is ready to install', function () {
    $result = ServiceCatalog::compatibility(null, ['console' => '>=0.7.0', 'api' => '>=1.6.0'], ['console' => '0.8.1', 'api' => '2.0.4']);

    expect($result)->toBe([
        'verdict'  => 'ready',
        'reason'   => 'ok',
        'requires' => ['console' => '>=0.7.0', 'api' => '>=1.6.0'],
        'current'  => ['console' => '0.8.1', 'api' => '2.0.4'],
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
