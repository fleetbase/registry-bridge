<?php

use Fleetbase\RegistryBridge\Console\Commands\RegisterInstance;
use Fleetbase\RegistryBridge\Support\InstanceRegistration;
use Fleetbase\RegistryBridge\Tests\Support\RecordingInstanceRegistration;
use Fleetbase\RegistryBridge\Tests\Support\RegistryServiceFake;
use Illuminate\Config\Repository as Config;
use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Events\Dispatcher as Events;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    $this->service                           = new RegistryServiceFake();
    RecordingInstanceRegistration::$settings = [];

    // Commands ask the application whether they run under tests (prompts setup).
    $this->container = new class() extends Container {
        public function runningUnitTests(): bool
        {
            return true;
        }
    };
    $this->container->instance('cache', $this->service->cache);
    $this->container->instance(Factory::class, $this->service->http);
    $this->container->instance('log', new Psr\Log\NullLogger());
    $this->container->instance('config', new Config([
        'app'             => ['name' => 'Acme Fleet', 'url' => 'https://fleet.example.test'],
        'registry-bridge' => ['registry' => ['instance_kind' => 'self_hosted']],
    ]));
    $this->container->instance('encrypter', new class() implements StringEncrypter {
        public function encryptString($value)
        {
            return 'enc:' . base64_encode(strrev($value));
        }

        public function decryptString($payload)
        {
            return strrev((string) base64_decode(substr($payload, 4)));
        }
    });
    $this->container->instance(InstanceRegistration::class, new RecordingInstanceRegistration());
    Container::setInstance($this->container);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->container);

    $this->artisan = function (array $input, ?string $stdin = null): array {
        $app = new Artisan($this->container, new Events($this->container), 'test');
        $app->setAutoExit(false);
        $command = new RegisterInstance();
        $command->setLaravel($this->container);
        $app->add($command);

        $input = new ArrayInput(['command' => 'registry:register', ...$input]);
        if ($stdin !== null) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $stdin);
            rewind($stream);
            $input->setStream($stream);
        }
        $output = new BufferedOutput();
        $code   = $app->run($input, $output);

        return [$code, $output->fetch()];
    };
});

afterEach(function () {
    Container::setInstance(null);
});

test('registry:register --json stores the token encrypted and prints the summary without it', function () {
    $this->service->on('POST', 'v1/instances/register', 'instances-register');

    [$code, $output] = ($this->artisan)(['--code' => 'w9qa-sc8r-37jt-9pa8', '--service-url' => RegistryServiceFake::SERVICE_URL, '--json' => true]);

    $summary  = json_decode($output, true);
    $settings = RecordingInstanceRegistration::$settings;
    $request  = $this->service->requestsTo('POST', 'v1/instances/register')[0];
    expect($code)->toBe(0)
        ->and($output)->not->toContain(RegistryServiceFake::INSTANCE_TOKEN)
        ->and(array_keys($summary))->toBe(['id', 'name', 'host', 'kind', 'status', 'organization', 'service_url', 'token_hint', 'registered_at'])
        ->and($summary)->toMatchArray(['id' => 'inst_rsmdtgevid', 'service_url' => RegistryServiceFake::SERVICE_URL, 'token_hint' => 'flbi_…0000'])
        ->and($summary['organization'])->toBe(['id' => 'org_qqymqcyvgv', 'slug' => 'registry-demo-buyer', 'name' => 'Registry Demo Buyer'])
        ->and($request->data())->toMatchArray(['code' => 'W9QA-SC8R-37JT-9PA8', 'name' => 'Acme Fleet', 'host' => 'https://fleet.example.test', 'kind' => 'self_hosted'])
        ->and($settings[InstanceRegistration::SETTING_SERVICE_URL])->toBe(RegistryServiceFake::SERVICE_URL)
        ->and($settings[InstanceRegistration::SETTING_INSTANCE_TOKEN])->not->toContain(RegistryServiceFake::INSTANCE_TOKEN)
        ->and(InstanceRegistration::decryptToken($settings[InstanceRegistration::SETTING_INSTANCE_TOKEN], app('encrypter')))->toBe(RegistryServiceFake::INSTANCE_TOKEN)
        ->and($settings[InstanceRegistration::SETTING_INSTANCE])->toBe($summary)
        ->and(config('registry-bridge.registry.instance_token'))->toBe(RegistryServiceFake::INSTANCE_TOKEN);
});

test('registry:register reads the code from stdin and takes name and host overrides', function () {
    $this->service->on('POST', 'v1/instances/register', 'instances-register');

    [$code, $output] = ($this->artisan)(['--code-stdin' => true, '--service-url' => 'registry.example.test/', '--name' => 'Depot 7', '--host' => 'https://depot7.example.test', '--console-version' => 'v0.7.70'], "  w9qa-sc8r-37jt-9pa8\n");

    $request = $this->service->requestsTo('POST', 'v1/instances/register')[0];
    expect($code)->toBe(0)
        ->and($request->url())->toBe(RegistryServiceFake::SERVICE_URL . '/v1/instances/register')
        ->and($request->data())->toMatchArray(['code' => 'W9QA-SC8R-37JT-9PA8', 'name' => 'Depot 7', 'host' => 'https://depot7.example.test', 'console_version' => '0.7.70'])
        ->and($output)->toContain('Registered Acme Fleet (self-hosted) (inst_rsmdtgevid) with Registry Demo Buyer.')
        ->and($output)->toContain('flbi_…0000')
        ->and($output)->not->toContain(RegistryServiceFake::INSTANCE_TOKEN);
});

test('a refused code exits non-zero with the service message and stores nothing', function () {
    $this->service->on('POST', 'v1/instances/register', 'instances-register-invalid-code');

    [$code, $output] = ($this->artisan)(['--code-stdin' => true, '--service-url' => RegistryServiceFake::SERVICE_URL, '--json' => true], 'NOPE-NOPE');

    expect($code)->toBe(1)
        ->and(json_decode($output, true))->toBe(['error' => 'The registration code is invalid, expired or already used.'])
        ->and(RecordingInstanceRegistration::$settings)->toBe([]);
});

test('registration needs a service URL', function () {
    [$code, $output] = ($this->artisan)(['--code' => 'W9QA-SC8R', '--json' => true]);

    expect($code)->toBe(1)
        ->and(json_decode($output, true))->toBe(['error' => 'Set the Registry Service URL before registering this instance.'])
        ->and($this->service->requests)->toBe([]);
});

test('the configured service URL is used when none is passed', function () {
    config(['registry-bridge.registry.service_url' => RegistryServiceFake::SERVICE_URL]);
    $this->service->on('POST', 'v1/instances/register', 'instances-register');

    [$code] = ($this->artisan)(['--code' => 'W9QA-SC8R', '--json' => true]);

    expect($code)->toBe(0)
        ->and(RecordingInstanceRegistration::$settings[InstanceRegistration::SETTING_SERVICE_URL])->toBe(RegistryServiceFake::SERVICE_URL);
});

test('registering again sends the current token to the service that issued it, and to no other', function () {
    config([
        'registry-bridge.registry.service_url'    => RegistryServiceFake::SERVICE_URL,
        'registry-bridge.registry.instance_token' => 'flbi_previous0000000000000000000000000000000',
    ]);
    $this->service->on('POST', 'v1/instances/register', 'instances-register', 'instances-register');

    [$same]  = ($this->artisan)(['--code' => 'W9QA-SC8R', '--json' => true]);
    [$other] = ($this->artisan)(['--code' => 'W9QA-SC8R', '--service-url' => 'https://other-registry.example.test', '--json' => true]);

    $requests = $this->service->requestsTo('POST', 'v1/instances/register');
    expect([$same, $other])->toBe([0, 0])
        ->and($requests[0]->data()['previous_token'] ?? null)->toBe('flbi_previous0000000000000000000000000000000')
        ->and($requests[1]->data())->not->toHaveKey('previous_token');
});

test('exactly one of --code and --code-stdin is required, and an empty stdin code is refused', function () {
    [$neither, $neitherOutput] = ($this->artisan)(['--service-url' => RegistryServiceFake::SERVICE_URL, '--json' => true]);
    [$both]                    = ($this->artisan)(['--code' => 'A', '--code-stdin' => true, '--service-url' => RegistryServiceFake::SERVICE_URL, '--json' => true], 'B');
    [$empty, $emptyOutput]     = ($this->artisan)(['--code-stdin' => true, '--service-url' => RegistryServiceFake::SERVICE_URL], "\n");

    expect([$neither, $both, $empty])->toBe([1, 1, 1])
        ->and(json_decode($neitherOutput, true))->toBe(['error' => 'Pass the registration code with exactly one of --code or --code-stdin.'])
        ->and($emptyOutput)->toContain('Enter the registration code from your registry organization.')
        ->and($this->service->requests)->toBe([]);
});

test('an unreachable service exits non-zero with a connectivity message', function () {
    $this->service->http->fake(fn () => throw new Illuminate\Http\Client\ConnectionException('Could not resolve host'));

    [$code, $output] = ($this->artisan)(['--code' => 'W9QA-SC8R', '--service-url' => RegistryServiceFake::SERVICE_URL, '--json' => true]);

    expect($code)->toBe(1)
        ->and(json_decode($output, true)['error'])->toStartWith('Unable to reach the extensions registry at ' . RegistryServiceFake::SERVICE_URL);
});
