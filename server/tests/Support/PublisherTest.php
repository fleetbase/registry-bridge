<?php

use Fleetbase\RegistryBridge\Support\Publisher;

test('cloud accounts publish as their company', function () {
    $publisher = Publisher::fromAccount('cloud', 'company-1', null);

    expect($publisher->type())->toBe('cloud')
        ->and($publisher->uuid())->toBe('company-1')
        ->and($publisher->isCloud())->toBeTrue()
        ->and($publisher->toArray())->toBe(['publisher_type' => 'cloud', 'publisher_uuid' => 'company-1']);
});

test('developer accounts publish as their developer account', function () {
    $publisher = Publisher::fromAccount('developer', null, 'dev-1');

    expect($publisher->type())->toBe('developer')
        ->and($publisher->uuid())->toBe('dev-1')
        ->and($publisher->isDeveloper())->toBeTrue();
});

test('accounts without an identifying uuid have no publisher', function () {
    expect(Publisher::fromAccount('developer', 'company-1', null))->toBeNull()
        ->and(Publisher::fromAccount('cloud', null, 'dev-1'))->toBeNull();
});

test('ownership matches on publisher type and uuid', function () {
    $cloud     = Publisher::cloud('company-1');
    $developer = Publisher::developer('dev-1');

    expect($cloud->owns('cloud', 'company-1'))->toBeTrue()
        ->and($cloud->owns('cloud', 'company-2'))->toBeFalse()
        ->and($developer->owns('developer', 'dev-1'))->toBeTrue()
        ->and($developer->owns('developer', 'dev-2'))->toBeFalse();
});

test('ownership requires the publisher type to match even when uuids collide', function () {
    expect(Publisher::cloud('same-uuid')->owns('developer', 'same-uuid'))->toBeFalse()
        ->and(Publisher::developer('same-uuid')->owns('cloud', 'same-uuid'))->toBeFalse();
});

test('legacy extensions without publisher fields are owned by their company', function () {
    expect(Publisher::cloud('company-1')->owns('cloud', null, 'company-1'))->toBeTrue()
        ->and(Publisher::cloud('company-1')->owns(null, null, 'company-1'))->toBeTrue()
        ->and(Publisher::cloud('company-2')->owns('cloud', null, 'company-1'))->toBeFalse();
});

test('developer accounts never own legacy company extensions', function () {
    expect(Publisher::developer('company-1')->owns('cloud', null, 'company-1'))->toBeFalse();
});

test('a legacy company uuid is ignored once publisher fields are set', function () {
    expect(Publisher::cloud('company-1')->owns('developer', 'dev-1', 'company-1'))->toBeFalse();
});
