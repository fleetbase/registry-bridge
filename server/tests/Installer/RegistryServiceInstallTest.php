<?php

/*
 * Installs of Registry Service extensions through the queued installer: the
 * real ExtensionInstaller with ServiceReleaseSource, ServiceInstallReporter
 * and ServicePackageCredentials, against responses recorded from a running
 * service (server/tests/Fixtures/registry-service) and the sandbox's fake
 * composer and pnpm.
 */

use Fleetbase\RegistryBridge\Support\Installer\ArtifactDownloader;
use Fleetbase\RegistryBridge\Support\Installer\ExtensionInstaller;
use Fleetbase\RegistryBridge\Support\Installer\ExtensionRef;
use Fleetbase\RegistryBridge\Support\Installer\InstallerException;
use Fleetbase\RegistryBridge\Support\Installer\InstallerJobStore;
use Fleetbase\RegistryBridge\Support\Installer\InstallerQueue;
use Fleetbase\RegistryBridge\Support\Installer\InstallerService;
use Fleetbase\RegistryBridge\Support\Installer\PhpEngineCompat;
use Fleetbase\RegistryBridge\Support\Installer\ProcessResult;
use Fleetbase\RegistryBridge\Support\Installer\Release;
use Fleetbase\RegistryBridge\Support\Installer\ServiceInstallReporter;
use Fleetbase\RegistryBridge\Support\Installer\ServicePackageCredentials;
use Fleetbase\RegistryBridge\Support\Installer\ServiceReleaseSource;
use Fleetbase\RegistryBridge\Support\PackageAuth;
use Fleetbase\RegistryBridge\Support\RegistryClient;
use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Fleetbase\RegistryBridge\Tests\Installer\Fakes\InstallerSandbox;
use Fleetbase\RegistryBridge\Tests\Installer\Fakes\RecordingDispatcher;
use Fleetbase\RegistryBridge\Tests\Support\RegistryServiceFake;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

const HELLO_ID      = 'ext_lx52k33erf';
const HELLO_NPM     = '@registry-demo/hello-engine';
const HELLO_API     = 'registry-demo/hello-api';
const HELLO_TARBALL = RegistryServiceFake::SERVICE_URL . '/npm/@registry-demo/hello-engine/-/hello-engine-1.0.0.tgz';
const HELLO_DIST    = RegistryServiceFake::SERVICE_URL . '/npm/@registry-demo/hello-engine/-/dist/1.0.0';

/**
 * The free demo listing as the detail endpoint returns it (recorded summary plus its versions).
 *
 * @return array<string, mixed>
 */
function helloListing(): array
{
    $listing             = RegistryServiceFake::body('catalog-extensions')['data'][0];
    $listing['versions'] = [['id' => 'rel_fshrg663og', 'version' => '1.0.0', 'channel' => 'stable']];

    return $listing;
}

function helloRef(): ExtensionRef
{
    return new ExtensionRef(HELLO_ID, HELLO_ID, 'Registry Demo Hello', ExtensionRef::REGISTRY_SERVICE);
}

/**
 * @param array<string, mixed> $overrides
 *
 * @return array<string, mixed>
 */
function helloJob(string $action = 'install', array $overrides = []): array
{
    return ['id' => 'job-' . $action, 'action' => $action, 'extension' => helloRef()->toArray(), 'version' => null, 'company' => 'company-1', 'user' => 'user-1', ...$overrides];
}

/**
 * The demo listing with a prebuilt dist on its latest release, as the service serves it at HELLO_DIST.
 *
 * @return array{0: array<string, mixed>, 1: string} the listing and the dist archive
 */
function helloListingWithDist(InstallerSandbox $sandbox): array
{
    $archive                           = $sandbox->buildDist('1.0.0', ['name' => HELLO_NPM]);
    $listing                           = helloListing();
    $listing['latest_release']['dist'] = ['size' => filesize($archive), 'sha256' => hash_file('sha256', $archive), 'integrity' => null, 'built_against' => ['@fleetbase/ember-ui' => InstallerSandbox::UI, '@fleetbase/ember-core' => InstallerSandbox::CORE]];

    return [$listing, $archive];
}

/**
 * @param callable(string $url, string $target, array<string, string> $headers): void|null $fetcher HTTP downloads
 */
function serviceInstaller(InstallerSandbox $sandbox, RegistryConfig $config, ?callable $fetcher = null): ExtensionInstaller
{
    $client = RegistryClient::fromConfig($config);
    $paths  = $sandbox->config();
    $auth   = new PackageAuth($config);

    return new ExtensionInstaller(
        $paths,
        new ServiceReleaseSource($client, $config),
        $sandbox->runner,
        $sandbox->publisher,
        new PhpEngineCompat(),
        $sandbox->recorder,
        new ArtifactDownloader($paths->requireChecksums, null, $fetcher, fn (string $url) => $auth->downloadHeaders($url)),
        new ServiceInstallReporter($client, $config),
        new ServicePackageCredentials(new PackageAuth($config), $paths->composerBinary, $paths->pnpmBinary),
    );
}

/**
 * Bodies of the install reports sent to the instance API.
 *
 * @return array<int, array<string, mixed>>
 */
function installReports(RegistryServiceFake $service): array
{
    return array_map(fn ($request) => $request->data(), $service->requestsTo('POST', 'v1/instances/me/installs'));
}

beforeEach(function () {
    $this->service = new RegistryServiceFake();
    $this->service->respondWith('GET', 'v1/catalog/extensions/' . HELLO_ID, 200, helloListing());
    $this->sandbox = new InstallerSandbox();

    // pnpm takes `@scope/name@<tarball url>`; the url holds another `@scope`, so split after the name
    $this->sandbox->runner->on(['pnpm', 'add'], function (array $command) {
        $at                                                = strpos($command[2], '@', 1);
        $file                                              = $this->sandbox->console . '/package.json';
        $json                                              = json_decode((string) file_get_contents($file), true);
        $json['dependencies'][substr($command[2], 0, $at)] = substr($command[2], $at + 1);
        file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT));

        return ['Packages: +1', 'Done in 1s'];
    });
});

afterEach(function () {
    $this->sandbox->cleanup();
});

test('a service release installs from the service package endpoints with credentials only in the process environment', function () {
    $this->service->on('POST', 'v1/instances/me/installs', 'instances-installs-installing', 'instances-installs-installed');

    $result = serviceInstaller($this->sandbox, RegistryServiceFake::config())->run(helloJob());

    expect($result['status'])->toBe('completed')
        ->and($result['release']['source'])->toBe('registry-service')
        ->and($result['release']['engine']['tarball'])->toBe(HELLO_TARBALL)
        ->and($this->sandbox->runner->commands())->toBe([
            'composer config repositories.fleetbase-registry-service {"type":"composer","url":"https://registry.example.test/composer","canonical":false}',
            'composer require ' . HELLO_API . ':1.0.0 --no-interaction',
            'pnpm add ' . HELLO_NPM . '@' . HELLO_TARBALL . ' --config.ignore-workspace-root-check=true',
            'pnpm build --environment production',
            'php artisan config:clear',
            'php artisan route:clear',
            'php artisan view:clear',
            'php artisan event:clear',
            'php artisan queue:restart',
        ]);

    $env = array_column($this->sandbox->runner->calls, 'env');
    expect(json_decode($env[1]['COMPOSER_AUTH'], true))->toBe(['bearer' => ['registry.example.test' => RegistryServiceFake::INSTANCE_TOKEN]])
        ->and($env[2]['FLEETBASE_REGISTRY_TOKEN'])->toBe(RegistryServiceFake::INSTANCE_TOKEN)
        ->and($env[2]['npm_config_userconfig'])->toBe(PackageAuth::npmrcPath())
        ->and($env[4])->toBe([]);
    foreach ($this->sandbox->runner->commands() as $command) {
        expect($command)->not->toContain(RegistryServiceFake::INSTANCE_TOKEN);
    }

    expect(installReports($this->service))->toBe([
        ['extension' => HELLO_ID, 'version' => '1.0.0', 'status' => 'installing', 'installed_by' => 'user-1'],
        ['extension' => HELLO_ID, 'version' => '1.0.0', 'status' => 'installed', 'installed_by' => 'user-1'],
    ])
        ->and($this->sandbox->recorder->isInstalled('company-1', helloRef()))->toBeTrue()
        ->and(json_decode((string) file_get_contents($this->sandbox->console . '/package.json'), true)['dependencies'][HELLO_NPM])->toBe(HELLO_TARBALL);
});

test('a service release with a prebuilt dist installs as a runtime engine, downloaded with the instance token', function () {
    [$listing, $archive] = helloListingWithDist($this->sandbox);
    $this->service->respondWith('GET', 'v1/catalog/extensions/' . HELLO_ID, 200, $listing);
    $this->service->on('POST', 'v1/instances/me/installs', 'instances-installs-installing', 'instances-installs-installed');
    $downloads = [];
    $fetcher   = function (string $url, string $target, array $headers) use ($archive, &$downloads) {
        $downloads[] = [$url, $headers];
        copy($archive, $target);
    };

    $result = serviceInstaller($this->sandbox, RegistryServiceFake::config(), $fetcher)->run(helloJob());

    expect($result['status'])->toBe('completed')
        ->and($result['mode'])->toBe('runtime')
        ->and($result['stages'])->toBe(['resolve', 'download', 'snapshot', 'composer', 'engine', 'caches', 'record'])
        ->and($result['release']['engine']['prebuilt'])->toBeTrue()
        ->and($result['release']['artifacts'])->toBe([['role' => 'dist', 'uri' => HELLO_DIST, 'checksum' => 'sha256-' . hash_file('sha256', $archive), 'filename' => 'hello-engine-1.0.0-dist']])
        ->and($downloads)->toBe([[HELLO_DIST, ['Authorization' => 'Bearer ' . RegistryServiceFake::INSTANCE_TOKEN]]])
        ->and($this->sandbox->runner->commands())->toBe([
            'composer config repositories.fleetbase-registry-service {"type":"composer","url":"https://registry.example.test/composer","canonical":false}',
            'composer require ' . HELLO_API . ':1.0.0 --no-interaction',
            'php artisan config:clear',
            'php artisan route:clear',
            'php artisan view:clear',
            'php artisan event:clear',
            'php artisan queue:restart',
        ])
        ->and($this->sandbox->manifest()->has(HELLO_NPM))->toBeTrue()
        ->and(is_file($this->sandbox->runtime . '/' . HELLO_NPM . '/manifest.json'))->toBeTrue()
        ->and($this->sandbox->recorder->installs['company-1'][HELLO_ID])->toMatchArray(['version' => '1.0.0', 'mode' => 'runtime', 'source' => 'registry-service']);
    expect(json_encode($result))->not->toContain(RegistryServiceFake::INSTANCE_TOKEN);
});

test('a dist whose checksum does not match the service payload fails the download before anything changes', function () {
    [$listing, $archive]                         = helloListingWithDist($this->sandbox);
    $listing['latest_release']['dist']['sha256'] = str_repeat('0', 64);
    $this->service->respondWith('GET', 'v1/catalog/extensions/' . HELLO_ID, 200, $listing);
    $this->service->on('POST', 'v1/instances/me/installs', 'instances-installs-installing', 'instances-installs-failed');

    $result = serviceInstaller($this->sandbox, RegistryServiceFake::config(), fn (string $url, string $target) => copy($archive, $target))->run(helloJob());

    expect($result['status'])->toBe('failed')
        ->and($result['failed_stage'])->toBe('download')
        ->and($result['message'])->toContain('Checksum mismatch for the dist artifact')
        ->and($this->sandbox->runner->calls)->toBe([]);
});

test('a console that cannot take the prebuilt dist installs the engine package and rebuilds instead', function (string $reason) {
    [$listing, $archive] = helloListingWithDist($this->sandbox);
    $this->service->respondWith('GET', 'v1/catalog/extensions/' . HELLO_ID, 200, $listing);
    $this->service->on('POST', 'v1/instances/me/installs', 'instances-installs-installing', 'instances-installs-installed');
    match ($reason) {
        'built in'        => $this->sandbox->runner->run(['pnpm', 'add', HELLO_NPM . '@' . HELLO_TARBALL], $this->sandbox->console),
        'no host modules' => unlink($this->sandbox->console . '/dist/host-modules.json'),
        'incompatible'    => $this->sandbox->writeHostModules('0.5.1'),
    };
    $this->sandbox->runner->calls = [];

    $result = serviceInstaller($this->sandbox, RegistryServiceFake::config(), fn (string $url, string $target) => copy($archive, $target))->run(helloJob());

    expect($result['status'])->toBe('completed')
        ->and($result['mode'])->toBe('build')
        ->and($result['stages'])->toBe(['resolve', 'download', 'snapshot', 'composer', 'engine', 'build', 'caches', 'record'])
        ->and($result['prebuilt_skipped'])->toContain(match ($reason) {
            'built in'        => 'is built into this console',
            'no host modules' => 'no host-modules.json',
            'incompatible'    => 'not compatible',
        })
        ->and($this->sandbox->runner->commands())->toContain('pnpm add ' . HELLO_NPM . '@' . HELLO_TARBALL . ' --config.ignore-workspace-root-check=true', 'pnpm build --environment production')
        ->and($this->sandbox->manifest()->has(HELLO_NPM))->toBeFalse();
})->with(['built in', 'no host modules', 'incompatible']);

test('a release from an older service without dist metadata keeps the rebuild path', function () {
    $release = (new ServiceReleaseSource(RegistryClient::fromConfig(RegistryServiceFake::config()), RegistryServiceFake::config()))->release(helloRef(), helloListing());

    expect($release->artifacts)->toBe([])
        ->and($release->hasPrebuiltEngine())->toBeFalse();
});

test('an older version takes the dist from its versions entry', function () {
    $listing             = helloListing();
    $listing['versions'] = [
        ['id' => 'rel_fshrg663og', 'version' => '1.0.0', 'channel' => 'stable', 'dist' => null],
        ['id' => 'rel_old', 'version' => '0.9.0', 'channel' => 'stable', 'dist' => ['size' => 10, 'sha256' => str_repeat('AB', 32), 'integrity' => null, 'built_against' => []]],
    ];
    $source = new ServiceReleaseSource(RegistryClient::fromConfig(RegistryServiceFake::config()), RegistryServiceFake::config());

    $old = $source->release(helloRef(), $listing, '0.9.0');
    expect($old->artifact('dist')?->uri)->toBe(RegistryServiceFake::SERVICE_URL . '/npm/@registry-demo/hello-engine/-/dist/0.9.0')
        ->and($old->artifact('dist')?->checksum)->toBe('sha256-' . str_repeat('ab', 32))
        ->and($source->release(helloRef(), $listing, '1.0.0')->artifacts)->toBe([]);
});

test('download headers carry the instance token only to the configured service', function () {
    $auth = new PackageAuth(RegistryServiceFake::config());

    expect($auth->downloadHeaders(HELLO_DIST))->toBe(['Authorization' => 'Bearer ' . RegistryServiceFake::INSTANCE_TOKEN])
        ->and($auth->downloadHeaders('https://cdn.example.test/npm/x/-/dist/1.0.0'))->toBe([])
        ->and($auth->downloadHeaders(RegistryServiceFake::SERVICE_URL . '.evil.test/npm/x'))->toBe([])
        ->and((new PackageAuth(RegistryServiceFake::config(['instance_token' => null])))->downloadHeaders(HELLO_DIST))->toBe([]);
});

test('a licence refusal from the service stops the job before anything changes', function () {
    $this->service->on('POST', 'v1/instances/me/installs', 'instances-installs-paid-forbidden');

    $result = serviceInstaller($this->sandbox, RegistryServiceFake::config())->run(helloJob());

    expect($result['status'])->toBe('failed')
        ->and($result['failed_stage'])->toBe('resolve')
        ->and($result['message'])->toContain('does not hold an active licence')
        ->and($result['rollback']['performed'])->toBeFalse()
        ->and($this->sandbox->runner->calls)->toBe([])
        ->and(installReports($this->service))->toHaveCount(1)
        ->and($this->sandbox->recorder->isInstalled('company-1', helloRef()))->toBeFalse();
});

test('a failed package step rolls back with credentials and is reported as failed', function () {
    $this->service->on('POST', 'v1/instances/me/installs', 'instances-installs-installing', 'instances-installs-failed');
    $this->sandbox->runner->on(['composer', 'require'], fn () => new ProcessResult(1, "Your requirements could not be resolved to an installable set of packages.\n"));

    $result = serviceInstaller($this->sandbox, RegistryServiceFake::config())->run(helloJob());

    expect($result['status'])->toBe('failed')
        ->and($result['failed_stage'])->toBe('composer')
        ->and($result['rollback']['performed'])->toBeTrue();

    $reports = installReports($this->service);
    expect(array_column($reports, 'status'))->toBe(['installing', 'failed'])
        ->and($reports[1]['error'])->toBe($result['message']);

    $rollback = array_values(array_filter($this->sandbox->runner->calls, fn ($call) => $call['command'][1] === 'install'));
    expect($rollback[0]['command'])->toBe(['composer', 'install', '--no-interaction'])
        ->and($rollback[0]['env'])->toHaveKey('COMPOSER_AUTH');
});

test('an install that succeeded survives a lost installed report', function () {
    $this->service
        ->on('POST', 'v1/instances/me/installs', 'instances-installs-installing')
        ->thenRespondWith('POST', 'v1/instances/me/installs', 502, ['errors' => ['Bad gateway']]);

    $result = serviceInstaller($this->sandbox, RegistryServiceFake::config())->run(helloJob());

    expect($result['status'])->toBe('completed')
        ->and($result['report_error'])->toBe('Bad gateway')
        ->and($this->sandbox->recorder->isInstalled('company-1', helloRef()))->toBeTrue();
});

test('installing from the service needs a registered instance', function () {
    $result = serviceInstaller($this->sandbox, RegistryServiceFake::config(['instance_token' => null]))->run(helloJob());

    expect($result['status'])->toBe('failed')
        ->and($result['message'])->toContain('Register this instance')
        ->and($this->service->requestsTo('POST', 'v1/instances/me/installs'))->toBe([])
        ->and($this->sandbox->runner->calls)->toBe([]);
});

test('a version the service has not published is refused', function () {
    $result = serviceInstaller($this->sandbox, RegistryServiceFake::config())->run(helloJob('update', ['version' => '9.9.9']));

    expect($result['status'])->toBe('failed')
        ->and($result['message'])->toBe('Registry Demo Hello has no published release 9.9.9 on the registry service.');
});

test('an uninstall removes the packages and releases the licence seat', function () {
    $this->service->on('DELETE', 'v1/instances/me/installs/' . HELLO_ID, 'instances-installs-uninstall');
    $this->sandbox->recorder->record('company-1', helloRef(), new Release(helloRef(), '1.0.0'));
    $this->sandbox->runner->run(['composer', 'require', HELLO_API . ':1.0.0'], $this->sandbox->api);
    $this->sandbox->runner->run(['pnpm', 'add', HELLO_NPM . '@' . HELLO_TARBALL], $this->sandbox->console);
    $this->sandbox->runner->calls = [];

    $result = serviceInstaller($this->sandbox, RegistryServiceFake::config())->run(helloJob('uninstall'));

    expect($result['status'])->toBe('completed')
        ->and($this->sandbox->runner->commands())->toContain('composer remove ' . HELLO_API . ' --no-interaction', 'pnpm remove ' . HELLO_NPM)
        ->and($this->service->requestsTo('DELETE', 'v1/instances/me/installs/' . HELLO_ID))->toHaveCount(1)
        ->and($this->sandbox->recorder->isInstalled('company-1', helloRef()))->toBeFalse();
});

test('an uninstall the service never saw still completes', function () {
    $this->service->respondWith('DELETE', 'v1/instances/me/installs/' . HELLO_ID, 404, ['errors' => ['This extension is not installed on this instance.']]);
    $this->sandbox->recorder->record('company-1', helloRef(), new Release(helloRef(), '1.0.0'));

    $result = serviceInstaller($this->sandbox, RegistryServiceFake::config())->run(helloJob('uninstall'));

    expect($result['status'])->toBe('completed')
        ->and($result)->not->toHaveKey('report_error');
});

test('the seat stays claimed while another company keeps the extension', function () {
    $this->sandbox->recorder->companies = ['company-1', 'company-2'];
    $this->sandbox->recorder->record('company-1', helloRef(), new Release(helloRef(), '1.0.0'));
    $this->sandbox->recorder->record('company-2', helloRef(), new Release(helloRef(), '1.0.0'));

    $result = serviceInstaller($this->sandbox, RegistryServiceFake::config())->run(helloJob('uninstall'));

    expect($result['status'])->toBe('completed')
        ->and($result['packages_kept'])->toBeTrue()
        ->and($this->service->requestsTo('DELETE', 'v1/instances/me/installs/' . HELLO_ID))->toBe([]);
});

test('preinstalled mode records the install only after the service accepts it', function () {
    $this->service
        ->on('GET', 'v1/catalog/extensions', 'catalog-extensions')
        ->on('POST', 'v1/instances/me/installs', 'instances-installs-installing', 'instances-installs-installed');
    $config  = RegistryServiceFake::config();
    $client  = RegistryClient::fromConfig($config);
    $locks   = new ArrayStore();
    $store   = new InstallerJobStore(new Repository($locks));
    $service = new InstallerService(new InstallerQueue($store, $locks, new RecordingDispatcher()), $store, new ServiceReleaseSource($client, $config), $this->sandbox->recorder, serviceInstaller($this->sandbox, $config), true, new ServiceInstallReporter($client, $config));

    $job = $service->request('install', HELLO_NPM, null, 'company-1', 'user-1');

    expect($job['status'])->toBe('completed')
        ->and($job['result']['mode'])->toBe('preinstalled')
        ->and(array_column(installReports($this->service), 'status'))->toBe(['installing', 'installed'])
        ->and($this->sandbox->runner->calls)->toBe([])
        ->and($this->sandbox->recorder->isInstalled('company-1', helloRef()))->toBeTrue();

    $this->service
        ->on('GET', 'v1/catalog/extensions/ext_gzh4wgc9nr', 'catalog-extension')
        ->on('POST', 'v1/instances/me/installs', 'instances-installs-paid-forbidden');
    expect(fn () => $service->request('install', 'registry-demo-pro', null, 'company-1'))->toThrow(InstallerException::class, 'does not hold an active licence');
    expect($this->sandbox->recorder->isInstalled('company-1', new ExtensionRef('ext_gzh4wgc9nr', 'ext_gzh4wgc9nr', 'Registry Demo Pro', ExtensionRef::REGISTRY_SERVICE)))->toBeFalse();
});

test('the service source finds extensions by package name, slug or id', function () {
    $this->service->on('GET', 'v1/catalog/extensions', 'catalog-extensions');
    $source = new ServiceReleaseSource(RegistryClient::fromConfig(RegistryServiceFake::config()), RegistryServiceFake::config());

    foreach ([HELLO_NPM, HELLO_API, 'registry-demo-hello', HELLO_ID] as $name) {
        $ref = $source->find($name);
        expect($ref)->toEqual(helloRef());
    }

    $this->service->on('GET', 'v1/catalog/extensions/unknown', 'catalog-extension-not-found');
    expect($source->find('@acme/unknown-engine'))->toBeNull()
        ->and($source->find('unknown'))->toBeNull();
});

test('the service source is skipped without a service URL', function () {
    $source = new ServiceReleaseSource(RegistryClient::fromConfig(new RegistryConfig([])), new RegistryConfig([]));

    expect($source->find(HELLO_NPM))->toBeNull()
        ->and($this->service->requests)->toBe([]);
});

test('an extension the service no longer lists resolves from its local install record', function () {
    $this->service->on('GET', 'v1/catalog/extensions/' . HELLO_ID, 'catalog-extension-not-found');
    $local  = fn (string $id) => $id === HELLO_ID ? ['id' => HELLO_ID, 'name' => 'Registry Demo Hello', 'packages' => ['npm' => HELLO_NPM, 'composer' => HELLO_API], 'latest_release' => ['version' => '1.0.0']] : null;
    $source = new ServiceReleaseSource(RegistryClient::fromConfig(RegistryServiceFake::config()), RegistryServiceFake::config(), $local);

    $release = $source->resolve(helloRef());

    expect($release->version)->toBe('1.0.0')
        ->and($release->enginePackage)->toBe(HELLO_NPM)
        ->and($release->engineRequirement())->toBe(HELLO_NPM . '@' . HELLO_TARBALL)
        ->and($release->composerRequirement())->toBe(HELLO_API . ':1.0.0');
});

test('an unreachable service fails the lookup with a clear message', function () {
    $this->service->respondWith('GET', 'v1/catalog/extensions', 503, ['errors' => ['Service Unavailable']]);
    $source = new ServiceReleaseSource(RegistryClient::fromConfig(RegistryServiceFake::config()), RegistryServiceFake::config());

    expect(fn () => $source->find(HELLO_NPM))->toThrow(InstallerException::class, 'The registry service could not be reached');
});

test('job records keep the extension source across the queue', function () {
    $ref = ExtensionRef::fromArray(helloRef()->toArray());

    expect($ref->isFromRegistryService())->toBeTrue()
        ->and(ExtensionRef::fromArray(['uuid' => 'u', 'id' => 'ext_x', 'name' => 'X'])->source)->toBe('registry-service');
});
