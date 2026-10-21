<?php

use Fleetbase\RegistryBridge\Support\Purchaser;

test('cloud accounts purchase as their company', function () {
    $purchaser = Purchaser::fromAccount('cloud', 'company-1', null);

    expect($purchaser)->not->toBeNull()
        ->and($purchaser->type())->toBe(Purchaser::TYPE_COMPANY)
        ->and($purchaser->uuid())->toBe('company-1')
        ->and($purchaser->isCompany())->toBeTrue()
        ->and($purchaser->isDeveloperAccount())->toBeFalse();
});

test('legacy registry users without an account type purchase as their company', function () {
    $purchaser = Purchaser::fromAccount(null, 'company-1', null);

    expect($purchaser->type())->toBe(Purchaser::TYPE_COMPANY)
        ->and($purchaser->uuid())->toBe('company-1');
});

test('developer accounts purchase as their developer account', function () {
    $purchaser = Purchaser::fromAccount('developer', null, 'dev-1');

    expect($purchaser->type())->toBe(Purchaser::TYPE_DEVELOPER_ACCOUNT)
        ->and($purchaser->uuid())->toBe('dev-1')
        ->and($purchaser->isDeveloperAccount())->toBeTrue()
        ->and($purchaser->toArray())->toBe([
            'purchaser_type' => Purchaser::TYPE_DEVELOPER_ACCOUNT,
            'purchaser_uuid' => 'dev-1',
        ]);
});

test('developer accounts never fall back to a company uuid', function () {
    expect(Purchaser::fromAccount('developer', 'company-1', null))->toBeNull();
});

test('accounts without an identifying uuid have no purchaser', function () {
    expect(Purchaser::fromAccount('cloud', null, null))->toBeNull()
        ->and(Purchaser::fromAccount('cloud', '', 'dev-1'))->toBeNull();
});

test('a purchaser can be rebuilt from stored type and uuid', function () {
    $purchaser = Purchaser::fromType(Purchaser::TYPE_COMPANY, 'company-1');

    expect($purchaser->isCompany())->toBeTrue()
        ->and($purchaser->uuid())->toBe('company-1');
});

test('unknown purchaser types are rejected', function () {
    expect(Purchaser::fromType('App\\Models\\User', 'user-1'))->toBeNull()
        ->and(Purchaser::fromType(null, 'company-1'))->toBeNull()
        ->and(Purchaser::fromType(Purchaser::TYPE_COMPANY, null))->toBeNull();
});
