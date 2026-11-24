<?php

use Fleetbase\RegistryBridge\Console\Commands\ManageMaintenance;
use Fleetbase\RegistryBridge\Exceptions\RegistryMaintenanceException;
use Fleetbase\RegistryBridge\Support\CloudInstallSync;
use Fleetbase\RegistryBridge\Support\Installer\ExtensionRef;
use Fleetbase\RegistryBridge\Support\Installer\Release;
use Fleetbase\RegistryBridge\Support\Installer\ServiceInstallReporter;
use Fleetbase\RegistryBridge\Support\RegistryClient;
use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Fleetbase\RegistryBridge\Support\RegistryMaintenance;
use Fleetbase\RegistryBridge\Support\RegistrySettings;
use Fleetbase\RegistryBridge\Tests\Support\RecordingMaintenance;
use Fleetbase\RegistryBridge\Tests\Support\RegistryServiceFake;
use Illuminate\Config\Repository as Config;
use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher as Events;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * The cloud instance acting for each company: tenant headers, per-company instance state, install reports, the
 * post-import install sync, and the cutover freeze.
 */

const ACME   = '7f0c2c3e-0d1b-4f43-9a7e-3b1f9d0c1a22';
const GLOBEX = '0b3f6d8e-41a2-4c55-8f1e-6a2d9c7b5e10';

function cloudConfig(array $overrides = []): RegistryConfig
{
    return RegistryServiceFake::config(['instance_kind' => 'cloud', ...$overrides]);
}

/**
 * @return array{0: ExtensionRef, 1: Release}
 */
function serviceExtension(): array
{
    $ref = new ExtensionRef('ext_hello', 'ext_hello', 'Registry Demo Hello');

    return [$ref, new Release($ref, '1.0.0', 'registry-demo/hello-api', null, '@registry-demo/hello-engine', null, [], 'registry-service')];
}

beforeEach(function () {
    $this->service = new RegistryServiceFake();
});

test('the cloud instance names the company in every instance request', function () {
    $this->service->on('GET', 'v1/instances/me', 'instances-me-cloud-tenant');
    $client = RegistryClient::fromConfig(cloudConfig());

    $client->forCompany(ACME)->instance();
    $client->instance();

    [$forAcme, $forNobody] = $this->service->requestsTo('GET', 'v1/instances/me');
    expect($forAcme->header('X-Registry-Tenant'))->toBe([ACME])
        ->and($forNobody->hasHeader('X-Registry-Tenant'))->toBeFalse();
});

test('a self-hosted instance never names a company', function () {
    $this->service->on('GET', 'v1/instances/me', 'instances-me');

    RegistryClient::fromConfig(RegistryServiceFake::config())->forCompany(ACME)->instance();

    expect($this->service->requestsTo('GET', 'v1/instances/me')[0]->hasHeader('X-Registry-Tenant'))->toBeFalse();
});

test('instance state is cached per company, the public catalog once for all', function () {
    $this->service->on('GET', 'v1/instances/me', 'instances-me-cloud-tenant')
        ->on('GET', 'v1/catalog/extensions', 'catalog-extensions');
    $client = RegistryClient::fromConfig(cloudConfig());

    foreach ([ACME, GLOBEX, ACME] as $company) {
        $client->forCompany($company)->instance();
        $client->forCompany($company)->catalogExtensions();
    }

    expect($this->service->requestsTo('GET', 'v1/instances/me'))->toHaveCount(2)
        ->and($this->service->requestsTo('GET', 'v1/catalog/extensions'))->toHaveCount(1);
});

test('install reports on the cloud instance are for the installing company, and every uninstall releases its seat', function () {
    $this->service->on('POST', 'v1/instances/me/installs', 'instances-installs-installed')
        ->on('DELETE', 'v1/instances/me/installs/ext_hello', 'instances-installs-uninstall');
    $config          = cloudConfig();
    $reporter        = new ServiceInstallReporter(RegistryClient::fromConfig($config), $config);
    [$ref, $release] = serviceExtension();

    $reporter->starting('install', $ref, $release, ACME);
    $reporter->finished('install', $ref, $release, ACME);
    // Globex still has it installed: on the cloud instance Acme's seat is still its own to release
    $reporter->finished('uninstall', $ref, $release, ACME, null, false);

    $reports = $this->service->requestsTo('POST', 'v1/instances/me/installs');
    expect(array_map(fn ($r) => $r->header('X-Registry-Tenant')[0] ?? null, $reports))->toBe([ACME, ACME])
        ->and($this->service->requestsTo('DELETE', 'v1/instances/me/installs/ext_hello')[0]->header('X-Registry-Tenant'))->toBe([ACME]);
});

test('a self-hosted instance keeps its seat while another company has the extension', function () {
    $config          = RegistryServiceFake::config();
    $reporter        = new ServiceInstallReporter(RegistryClient::fromConfig($config), $config);
    [$ref, $release] = serviceExtension();

    $reporter->finished('uninstall', $ref, $release, ACME, null, false);

    expect($this->service->requests)->toBe([]);
});

test('the install sync records what each company had installed, page by page, and skips unknown companies', function () {
    $this->service->on('GET', 'v1/instances/me/tenant-installs', 'instances-tenant-installs-page-1', 'instances-tenant-installs-page-2');
    $rows       = array_merge(RegistryServiceFake::body('instances-tenant-installs-page-1')['data'], RegistryServiceFake::body('instances-tenant-installs-page-2')['data']);
    $known      = array_values(array_unique(array_column($rows, 'company_uuid')));
    $unknown    = array_pop($known);
    $recorded   = [];
    $sync       = new CloudInstallSync(
        RegistryClient::fromConfig(cloudConfig()),
        RegistryServiceFake::SERVICE_URL,
        fn (string $company) => in_array($company, $known, true),
        function (string $company, array $attributes, bool $dryRun) use (&$recorded) {
            $key            = $company . '|' . $attributes['extension_id'];
            $outcome        = isset($recorded[$key]) ? 'unchanged' : 'created';
            $recorded[$key] = $attributes;

            return $outcome;
        }
    );

    $counts = $sync->run();

    $skipped = count(array_filter($rows, fn ($row) => $row['company_uuid'] === $unknown));
    expect($counts)->toBe(['created' => count($rows) - $skipped, 'updated' => 0, 'unchanged' => 0, 'skipped' => $skipped, 'companies' => count($known)])
        ->and($this->service->requestsTo('GET', 'v1/instances/me/tenant-installs'))->toHaveCount(2);

    $first = $rows[0];
    expect($recorded[$first['company_uuid'] . '|' . $first['extension']['id']])->toBe([
        'extension_id'     => $first['extension']['id'],
        'slug'             => $first['extension']['slug'],
        'name'             => $first['extension']['name'],
        'npm_package'      => $first['packages']['npm'] ?? null,
        'composer_package' => $first['packages']['composer'] ?? null,
        'version'          => $first['release']['version'],
        'release_id'       => $first['release']['id'],
        'service_url'      => RegistryServiceFake::SERVICE_URL,
    ]);
});

test('maintenance is on when stored or forced, with the stored message first', function () {
    $off = cloudConfig()->maintenance();
    expect($off['enabled'])->toBeFalse()->and($off['message'])->toBe(RegistryConfig::DEFAULT_MAINTENANCE_MESSAGE);

    $stored = cloudConfig(['maintenance_window' => ['enabled' => true, 'message' => 'Back at 14:00 UTC', 'since' => '2026-11-01T13:00:00+00:00']])->maintenance();
    expect($stored)->toBe(['enabled' => true, 'forced' => false, 'message' => 'Back at 14:00 UTC', 'since' => '2026-11-01T13:00:00+00:00']);

    $forced = cloudConfig(['maintenance' => ['enabled' => 'true', 'message' => 'From the environment'], 'maintenance_window' => ['enabled' => false]])->maintenance();
    expect($forced['enabled'])->toBeTrue()->and($forced['forced'])->toBeTrue()->and($forced['message'])->toBe('From the environment');

    expect(fn () => RegistryMaintenance::guard(cloudConfig(['maintenance_window' => ['enabled' => true]])))
        ->toThrow(RegistryMaintenanceException::class, RegistryConfig::DEFAULT_MAINTENANCE_MESSAGE);
    RegistryMaintenance::guard(cloudConfig());
});

test('registry:maintenance turns the freeze on and off through the settings', function () {
    $container = new class extends Container {
        public function runningUnitTests(): bool
        {
            return true;
        }
    };
    $container->instance('cache', $this->service->cache);
    $container->instance('config', new Config(['registry-bridge' => ['registry' => ['maintenance' => ['enabled' => false]]]]));
    $container->instance(RegistryMaintenance::class, new RecordingMaintenance());
    $container->instance(RegistrySettings::APPLIED, true);
    Container::setInstance($container);
    RecordingMaintenance::$settings = [];

    $run = function (array $input) use ($container) {
        $app = new Artisan($container, new Events($container), 'test');
        $app->setAutoExit(false);
        $command = new ManageMaintenance();
        $command->setLaravel($container);
        $app->add($command);
        $output = new BufferedOutput();

        return [$app->run(new ArrayInput(['command' => 'registry:maintenance', ...$input]), $output), $output->fetch()];
    };

    [$code, $output] = $run(['action' => 'on', '--message' => 'Moving to the new registry until 14:00 UTC.']);
    expect($code)->toBe(0)
        ->and($output)->toContain('Maintenance: ON')
        ->and($output)->toContain('Message: Moving to the new registry until 14:00 UTC.')
        ->and(RecordingMaintenance::$settings[RegistryMaintenance::SETTING]['enabled'])->toBeTrue();

    [$code, $output] = $run(['action' => 'off']);
    expect($code)->toBe(0)->and($output)->toContain('Maintenance: off')
        ->and(RecordingMaintenance::$settings[RegistryMaintenance::SETTING])->toBe(['enabled' => false]);

    config(['registry-bridge.registry.maintenance' => ['enabled' => true]]);
    [$code, $output] = $run(['action' => 'off']);
    expect($code)->toBe(1)->and($output)->toContain('REGISTRY_MAINTENANCE=true still forces it on');

    [$code] = $run(['action' => 'maybe']);
    expect($code)->toBe(2);

    Container::setInstance(null);
});
