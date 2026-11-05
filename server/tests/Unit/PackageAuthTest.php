<?php

use Fleetbase\RegistryBridge\Support\PackageAuth;
use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Fleetbase\RegistryBridge\Tests\Support\RegistryServiceFake;

test('Composer auth comes from COMPOSER_AUTH keyed by the service host', function () {
    $auth = new PackageAuth(RegistryServiceFake::config());

    expect(json_decode($auth->composerEnv()['COMPOSER_AUTH'], true))->toBe(['bearer' => ['registry.example.test' => RegistryServiceFake::INSTANCE_TOKEN]])
        ->and(PackageAuth::composerHostKey('http://127.0.0.1:8765'))->toBe('127.0.0.1:8765');
});

test('an existing COMPOSER_AUTH keeps its other credentials', function () {
    $auth     = new PackageAuth(RegistryServiceFake::config());
    $existing = json_encode(['bearer' => ['repo.example.test' => 'other'], 'github-oauth' => ['github.com' => 'gh']]);

    expect(json_decode($auth->composerEnv($existing)['COMPOSER_AUTH'], true))->toBe([
        'bearer'       => ['repo.example.test' => 'other', 'registry.example.test' => RegistryServiceFake::INSTANCE_TOKEN],
        'github-oauth' => ['github.com' => 'gh'],
    ]);
});

test('npm auth comes from the environment and a placeholder-only npmrc', function () {
    $env = (new PackageAuth(RegistryServiceFake::config()))->npmEnv();

    expect($env)->toBe([
        'NPM_CONFIG_//registry.example.test/npm/:_authToken' => RegistryServiceFake::INSTANCE_TOKEN,
        'npm_config_userconfig'                              => PackageAuth::npmrcPath(),
        'FLEETBASE_REGISTRY_NPM_AUTH_KEY'                    => '//registry.example.test/npm/:_authToken',
        'FLEETBASE_REGISTRY_TOKEN'                           => RegistryServiceFake::INSTANCE_TOKEN,
    ]);

    $npmrc = (string) file_get_contents(PackageAuth::npmrcPath());
    expect($npmrc)->toContain('${FLEETBASE_REGISTRY_NPM_AUTH_KEY}=${FLEETBASE_REGISTRY_TOKEN}')
        ->and($npmrc)->not->toContain('flbi_');
});

test('no credentials are produced for an unregistered instance', function () {
    $auth = new PackageAuth(RegistryServiceFake::config(['instance_token' => null]));

    expect($auth->composerEnv())->toBe([])
        ->and($auth->npmEnv())->toBe([]);
});

test('engines are added by tarball URL so their scope is not remapped', function () {
    $auth = new PackageAuth(RegistryServiceFake::config());

    expect($auth->npmTarballUrl('@registry-demo/hello-engine', '1.1.0'))->toBe('https://registry.example.test/npm/@registry-demo/hello-engine/-/hello-engine-1.1.0.tgz')
        ->and($auth->npmInstallSpec('@fleetbase/pallet-engine', '0.0.2'))->toBe('@fleetbase/pallet-engine@https://registry.example.test/npm/@fleetbase/pallet-engine/-/pallet-engine-0.0.2.tgz')
        ->and($auth->npmTarballUrl('unscoped-engine', '1.0.0'))->toBe('https://registry.example.test/npm/unscoped-engine/-/unscoped-engine-1.0.0.tgz')
        ->and(PackageAuth::composerInstallSpec('registry-demo/hello-api', '1.1.0'))->toBe('registry-demo/hello-api:1.1.0');
});

test('no credentials are produced without a service URL', function () {
    $auth = new PackageAuth(new RegistryConfig(['service_url' => '']));

    expect($auth->composerEnv())->toBe([])
        ->and($auth->npmEnv())->toBe([])
        ->and($auth->composerRepository())->toBeNull()
        ->and($auth->npmInstallSpec('@fleetbase/pallet-engine', '0.0.2'))->toBe('@fleetbase/pallet-engine@0.0.2');
});
