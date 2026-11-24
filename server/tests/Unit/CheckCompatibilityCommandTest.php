<?php

/*
 * registry:compat asks the service whether the installed extensions work on a target core version
 * (`GET /v1/instances/me/compatibility`, recorded) before an upgrade.
 */

use Fleetbase\RegistryBridge\Console\Commands\CheckCompatibility;
use Fleetbase\RegistryBridge\Support\RegistryClient;
use Fleetbase\RegistryBridge\Tests\Support\RegistryServiceFake;
use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher as Events;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

const COMPAT_PATH = 'v1/instances/me/compatibility';

beforeEach(function () {
    $this->service   = new RegistryServiceFake();
    $this->container = new class() extends Container {
        public function runningUnitTests(): bool
        {
            return true;
        }
    };
    $this->container->instance(RegistryClient::class, RegistryClient::fromConfig(RegistryServiceFake::config()));

    $this->compat = function (array $input): array {
        $app = new Artisan($this->container, new Events($this->container), 'test');
        $app->setAutoExit(false);
        $command = new CheckCompatibility();
        $command->setLaravel($this->container);
        $app->add($command);
        $output = new BufferedOutput();
        $code   = $app->run(new ArrayInput(['command' => 'registry:compat', ...$input]), $output);

        return [$code, $output->fetch()];
    };
});

test('a blocked extension fails the check and is named', function () {
    $this->service->on('GET', COMPAT_PATH, 'instances-me-compatibility-blocked');

    [$code, $output] = ($this->compat)(['--console' => '0.8.1', '--api' => '2.0.2']);

    expect($code)->toBe(CheckCompatibility::BLOCKED)
        ->and($output)->toContain('Registry Demo Hello')->toContain('update')->toContain('2.0.0')
        ->toContain('Acme Legacy Sync')->toContain('blocked')
        ->toContain('Blocked: acme-legacy-sync has no release for console 0.8.1, API 2.0.2.');

    $request = $this->service->requestsTo('GET', COMPAT_PATH)[0];
    expect($request->url())->toContain('console=0.8.1')->toContain('api=2.0.2')->not->toContain('host')
        ->and($request->header('Authorization')[0])->toBe('Bearer ' . RegistryServiceFake::INSTANCE_TOKEN);
});

test('a compatible target passes and says which extensions move with the upgrade', function () {
    $this->service->on('GET', COMPAT_PATH, 'instances-me-compatibility-ok');

    [$code, $output] = ($this->compat)(['--console' => '0.8.1', '--api' => '2.0.2']);

    expect($code)->toBe(0)
        ->and($output)->toContain('Every installed extension has a release for console 0.8.1, API 2.0.2. 2 need an update or downgrade with the upgrade.');
});

test('--json prints the service response; the exit code still reflects the result', function () {
    $this->service->on('GET', COMPAT_PATH, 'instances-me-compatibility-blocked');

    [$code, $output] = ($this->compat)(['--console' => '0.8.1', '--json' => true]);

    expect($code)->toBe(CheckCompatibility::BLOCKED)
        ->and(json_decode($output, true)['meta']['blocking_extensions'])->toBe(['acme-legacy-sync']);
});

test('host module versions come from --host and a target host-modules.json', function () {
    $this->service->on('GET', COMPAT_PATH, 'instances-me-compatibility-ok');
    $file = tempnam(sys_get_temp_dir(), 'host-modules');
    file_put_contents($file, json_encode(['modules' => ['@fleetbase/ember-core/services/fetch'], 'versions' => ['@fleetbase/ember-ui' => '0.4.9', '@fleetbase/ember-core' => '0.3.30', 'ember-source' => '5.4.0']]));

    [$code] = ($this->compat)(['--console' => '0.8.1', '--host-modules' => $file, '--host' => ['@fleetbase/ember-core=0.3.31'], '--policy' => 'exact']);
    unlink($file);

    $query = [];
    parse_str((string) parse_url($this->service->requestsTo('GET', COMPAT_PATH)[0]->url(), PHP_URL_QUERY), $query);
    expect($code)->toBe(0)
        ->and($query)->toBe(['console' => '0.8.1', 'host' => ['@fleetbase/ember-ui' => '0.4.9', '@fleetbase/ember-core' => '0.3.31'], 'policy' => 'exact']);
});

test('the check refuses to run without a target, and reports a service it cannot use', function () {
    [$code, $output] = ($this->compat)([]);
    expect($code)->toBe(CheckCompatibility::UNAVAILABLE)->and($output)->toContain('Pass the target version');

    [$code, $output] = ($this->compat)(['--console' => '0.8.1', '--host' => ['ember-core']]);
    expect($code)->toBe(CheckCompatibility::UNAVAILABLE)->and($output)->toContain('--host takes package=version');

    $this->service->on('GET', COMPAT_PATH, 'instances-me-compatibility-validation');
    [$code, $output] = ($this->compat)(['--console' => 'latest']);
    expect($code)->toBe(CheckCompatibility::UNAVAILABLE)->and($output)->toContain('The console must be a version such as 0.8.1.');

    $this->container->instance(RegistryClient::class, RegistryClient::fromConfig(RegistryServiceFake::config(['instance_token' => null])));
    [$code, $output] = ($this->compat)(['--console' => '0.8.1']);
    expect($code)->toBe(CheckCompatibility::UNAVAILABLE)->and($output)->toContain('not registered');
});
