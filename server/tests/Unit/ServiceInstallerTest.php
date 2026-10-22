<?php

use Fleetbase\RegistryBridge\Exceptions\InstallFailedException;
use Fleetbase\RegistryBridge\Support\PackageAuth;
use Fleetbase\RegistryBridge\Support\RegistryClient;
use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Fleetbase\RegistryBridge\Support\ServiceInstaller;
use Fleetbase\RegistryBridge\Tests\Support\RegistryServiceFake;

function installerFor(RegistryServiceFake $service, array &$ran, ?callable $runner = null, array $config = [], array &$events = []): ServiceInstaller
{
    $config = RegistryServiceFake::config($config);

    return new ServiceInstaller(
        RegistryClient::fromConfig($config),
        $config,
        $runner ?? function (array $command) use (&$ran) {
            $ran[] = $command;
        },
        function (string $process, string $step, int $progress) use (&$events) {
            $events[] = [$process, $step, $progress];
        }
    );
}

function installContext(array $overrides = []): array
{
    return array_merge([
        'api_path'     => '/fleetbase/api',
        'console_path' => '/fleetbase/console',
        'environment'  => 'production',
        'installed_by' => 'admin@example.test',
    ], $overrides);
}

beforeEach(function () {
    $this->service = new RegistryServiceFake();
});

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

test('the install plan points Composer at the service and injects credentials per process', function () {
    $ran       = [];
    $installer = installerFor($this->service, $ran);

    $plan = $installer->installPlan(['npm' => '@registry-demo/hello-engine', 'composer' => 'registry-demo/hello-api'], '1.0.0', installContext());

    expect(array_map(fn ($command) => implode(' ', $command['command']), $plan))->toBe([
        'composer config repositories.fleetbase-registry-service {"type":"composer","url":"https://registry.example.test/composer","canonical":false}',
        'composer require registry-demo/hello-api:1.0.0 --no-interaction',
        'pnpm add @registry-demo/hello-engine@https://registry.example.test/npm/@registry-demo/hello-engine/-/hello-engine-1.0.0.tgz',
        'pnpm build --environment production',
    ])
        ->and(array_column($plan, 'cwd'))->toBe(['/fleetbase/api', '/fleetbase/api', '/fleetbase/console', '/fleetbase/console'])
        ->and(array_column($plan, 'step'))->toBe(['server.install', 'server.install', 'engine.install', 'console.build'])
        ->and($plan[0]['env'])->toBe([])
        ->and($plan[1]['env'])->toHaveKey('COMPOSER_AUTH')
        ->and($plan[2]['env'])->toHaveKey('FLEETBASE_REGISTRY_TOKEN')
        ->and($plan[3]['env'])->toBe([]);

    // The token only ever travels in a process environment, never in an argument
    foreach ($plan as $command) {
        expect(implode(' ', $command['command']))->not->toContain(RegistryServiceFake::INSTANCE_TOKEN);
    }
});

test('an install reports installing, runs the plan, then reports installed', function () {
    $this->service->on('POST', 'v1/instances/me/installs', 'instances-installs-installing', 'instances-installs-installed');
    $listing   = RegistryServiceFake::body('catalog-extensions')['data'][0];
    $ran       = [];
    $installer = installerFor($this->service, $ran);

    $result = $installer->install($listing, installContext());

    $reports = array_map(fn ($request) => $request->data(), $this->service->requestsTo('POST', 'v1/instances/me/installs'));
    expect($reports)->toBe([
        ['extension' => 'ext_lx52k33erf', 'version' => '1.0.0', 'status' => 'installing', 'installed_by' => 'admin@example.test'],
        ['extension' => 'ext_lx52k33erf', 'version' => '1.0.0', 'status' => 'installed', 'installed_by' => 'admin@example.test'],
    ])
        ->and($ran)->toHaveCount(4)
        ->and($result['version'])->toBe('1.0.0')
        ->and($result['npm'])->toBe('@registry-demo/hello-engine')
        ->and($result['composer'])->toBe('registry-demo/hello-api')
        ->and($result['reported'])->toBeTrue()
        ->and($result['activation']['licence']['kind'])->toBe('free');
});

test('a licence refusal stops the install before any package is touched', function () {
    $this->service->on('POST', 'v1/instances/me/installs', 'instances-installs-paid-forbidden');
    $ran       = [];
    $installer = installerFor($this->service, $ran);

    expect(fn () => $installer->install(RegistryServiceFake::body('catalog-extension'), installContext()))
        ->toThrow(InstallFailedException::class, 'does not hold an active licence');
    expect($ran)->toBe([])
        ->and($this->service->requestsTo('POST', 'v1/instances/me/installs'))->toHaveCount(1);
});

test('a failed package step is reported as failed with the error', function () {
    $this->service->on('POST', 'v1/instances/me/installs', 'instances-installs-installing', 'instances-installs-failed');
    $ran       = [];
    $installer = installerFor($this->service, $ran, function (array $command) {
        if ($command['command'][1] === 'require') {
            throw new InstallFailedException('Your requirements could not be resolved to an installable set of packages.');
        }
    });

    expect(fn () => $installer->install(RegistryServiceFake::body('catalog-extension'), installContext()))
        ->toThrow(InstallFailedException::class, 'could not be resolved');

    $reports = array_map(fn ($request) => $request->data(), $this->service->requestsTo('POST', 'v1/instances/me/installs'));
    expect(array_column($reports, 'status'))->toBe(['installing', 'failed'])
        ->and($reports[1]['error'])->toBe('Your requirements could not be resolved to an installable set of packages.');
});

test('an install that succeeded locally survives a lost installed report', function () {
    // installing is accepted, then the service goes away before installed is reported
    $this->service
        ->on('POST', 'v1/instances/me/installs', 'instances-installs-installing')
        ->thenRespondWith('POST', 'v1/instances/me/installs', 502, ['errors' => ['Bad gateway']]);
    $ran       = [];
    $installer = installerFor($this->service, $ran);

    $result = $installer->install(RegistryServiceFake::body('catalog-extensions')['data'][0], installContext());

    expect($result['reported'])->toBeFalse()
        ->and($ran)->toHaveCount(4)
        ->and($this->service->requestsTo('POST', 'v1/instances/me/installs'))->toHaveCount(2);
});

test('preinstalled mode skips package steps but still reports and emits progress', function () {
    $this->service->on('POST', 'v1/instances/me/installs', 'instances-installs-installing', 'instances-installs-installed');
    $ran       = [];
    $events    = [];
    $installer = installerFor($this->service, $ran, null, [], $events);

    $installer->install(RegistryServiceFake::body('catalog-extensions')['data'][0], installContext(['preinstalled' => true]));

    expect($ran)->toBe([])
        ->and(array_values(array_unique(array_column($events, 1))))->toBe(['server.install', 'engine.install', 'console.build'])
        ->and($this->service->requestsTo('POST', 'v1/instances/me/installs'))->toHaveCount(2);
});

test('installing needs a registered instance', function () {
    $ran       = [];
    $installer = installerFor($this->service, $ran, null, ['instance_token' => null]);

    expect(fn () => $installer->install(RegistryServiceFake::body('catalog-extension'), installContext()))
        ->toThrow(InstallFailedException::class, 'Register this instance');
    expect($this->service->requests)->toBe([]);
});

test('an uninstall removes the packages, rebuilds and releases the licence seat', function () {
    $this->service->on('DELETE', 'v1/instances/me/installs/ext_lx52k33erf', 'instances-installs-uninstall');
    $ran       = [];
    $installer = installerFor($this->service, $ran);

    $result = $installer->uninstall(RegistryServiceFake::body('catalog-extensions')['data'][0], installContext());

    expect(array_map(fn ($command) => implode(' ', $command['command']), $ran))->toBe([
        'composer remove registry-demo/hello-api --no-interaction',
        'pnpm remove @registry-demo/hello-engine',
        'pnpm build --environment production',
    ])
        ->and($ran[0]['env'])->toHaveKey('COMPOSER_AUTH')
        ->and($result)->toBe(['extension' => 'ext_lx52k33erf', 'deactivated' => 1, 'reported' => true]);
});

test('an uninstall the service never saw still completes', function () {
    $this->service->on('DELETE', 'v1/instances/me/installs/ext_gzh4wgc9nr', 'instances-installs-uninstall-not-installed');
    $ran       = [];
    $installer = installerFor($this->service, $ran);

    $result = $installer->uninstall(RegistryServiceFake::body('catalog-extension'), installContext());

    expect($result['reported'])->toBeTrue()
        ->and($result['deactivated'])->toBe(0);
});

test('an uninstall can skip the report while other companies keep the extension', function () {
    $ran       = [];
    $installer = installerFor($this->service, $ran);

    $result = $installer->uninstall(RegistryServiceFake::body('catalog-extension'), installContext(['preinstalled' => true, 'report' => false]));

    expect($ran)->toBe([])
        ->and($this->service->requests)->toBe([])
        ->and($result['reported'])->toBeFalse();
});

test('the v1 fallback config produces no service credentials', function () {
    $auth = new PackageAuth(new RegistryConfig(['host' => 'https://registry.fleetbase.io', 'token' => 'legacy']));

    expect($auth->composerEnv())->toBe([])
        ->and($auth->npmEnv())->toBe([])
        ->and($auth->composerRepository())->toBeNull()
        ->and($auth->npmInstallSpec('@fleetbase/pallet-engine', '0.0.2'))->toBe('@fleetbase/pallet-engine@0.0.2');
});
