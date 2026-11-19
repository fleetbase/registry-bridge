<?php

use Fleetbase\RegistryBridge\Console\Commands\Initialize;
use Fleetbase\RegistryBridge\Console\Commands\RegisterCloudInstance;
use Fleetbase\RegistryBridge\Exceptions\RegistryServiceException;
use Fleetbase\RegistryBridge\Support\CloudRegistration;
use Fleetbase\RegistryBridge\Support\InstanceRegistration;
use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Fleetbase\RegistryBridge\Support\RegistrySettings;
use Fleetbase\RegistryBridge\Tests\Support\RecordingInstanceRegistration;
use Fleetbase\RegistryBridge\Tests\Support\RegistryServiceFake;
use Fleetbase\RegistryBridge\Tests\Support\StoredRegistrySettings;
use Illuminate\Config\Repository as Config;
use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Events\Dispatcher as Events;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

const CLOUD_SECRET = 'test-platform-secret-0123456789abcdef0123456789';

/**
 * Boot the container the support classes need, with the given registry settings (the config file) and stored
 * settings (the settings table).
 *
 * @param array<string, mixed> $registry
 * @param array<string, mixed> $stored
 */
function bootCloud(RegistryServiceFake $service, array $registry = [], array $stored = []): Container
{
    $container = new class extends Container {
        public function runningUnitTests(): bool
        {
            return true;
        }
    };
    $container->instance('cache', $service->cache);
    $container->instance(Factory::class, $service->http);
    $container->instance('log', new Psr\Log\NullLogger());
    $container->instance('config', new Config([
        'app'             => ['name' => 'Fleetbase Cloud', 'url' => 'https://api.fleetbase.test'],
        'registry-bridge' => ['registry' => array_merge([
            'service_url' => RegistryServiceFake::SERVICE_URL,
            'cloud'       => ['auto_register' => true, 'platform_secret' => CLOUD_SECRET],
        ], $registry)],
    ]));
    $container->instance('encrypter', new class implements StringEncrypter {
        public function encryptString($value)
        {
            return 'enc:' . base64_encode(strrev($value));
        }

        public function decryptString($payload)
        {
            return strrev((string) base64_decode(substr($payload, 4)));
        }
    });
    $container->instance(InstanceRegistration::class, new RecordingInstanceRegistration());
    $container->instance(RegistrySettings::class, new StoredRegistrySettings());
    StoredRegistrySettings::$stored = $stored;
    Container::setInstance($container);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);

    return $container;
}

/**
 * Settings table rows for an instance this service registered earlier.
 *
 * @return array<string, mixed>
 */
function storedCloudRegistration(string $token): array
{
    return [
        InstanceRegistration::SETTING_SERVICE_URL    => RegistryServiceFake::SERVICE_URL,
        InstanceRegistration::SETTING_INSTANCE_TOKEN => 'enc:' . base64_encode(strrev($token)),
        InstanceRegistration::SETTING_INSTANCE       => ['id' => 'inst_cloud', 'kind' => 'cloud', 'service_url' => RegistryServiceFake::SERVICE_URL],
    ];
}

/**
 * Run an artisan command in the booted container.
 *
 * @return array{0: int, 1: string}
 */
function runCloudCommand(Container $container, Illuminate\Console\Command $command, array $input = []): array
{
    $app = new Artisan($container, new Events($container), 'test');
    $app->setAutoExit(false);
    $command->setLaravel($container);
    $app->add($command);
    $output = new BufferedOutput();

    return [$app->run(new ArrayInput(['command' => $command->getName(), ...$input]), $output), $output->fetch()];
}

beforeEach(function () {
    $this->service                           = new RegistryServiceFake();
    RecordingInstanceRegistration::$settings = [];
    StoredRegistrySettings::$reads           = 0;
    RegistrySettings::flush();
});

afterEach(function () {
    RegistrySettings::flush();
    Container::setInstance(null);
});

test('registers the cloud instance with a body signed by the platform secret and stores the token', function () {
    bootCloud($this->service);
    $this->service->on('POST', 'v1/instances/register/cloud', 'instances-register-cloud');

    $result = CloudRegistration::ensure();

    expect($result['status'])->toBe(CloudRegistration::STATUS_REGISTERED)
        ->and($result['summary']['kind'])->toBe('cloud');

    /** @var Request $request */
    [$request] = $this->service->requestsTo('POST', 'v1/instances/register/cloud');
    $body      = $request->body();
    $timestamp = $request->header('X-Registry-Timestamp')[0];
    $nonce     = $request->header('X-Registry-Nonce')[0];

    expect(json_decode($body, true))->toMatchArray(['name' => 'Fleetbase Cloud', 'host' => 'https://api.fleetbase.test'])
        ->and(json_decode($body, true))->not->toHaveKey('previous_token')
        ->and(abs(time() - (int) $timestamp))->toBeLessThan(5)
        ->and(strlen($nonce))->toBe(32)
        ->and($request->header('X-Registry-Signature')[0])->toBe('v1=' . hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, CLOUD_SECRET))
        ->and($request->hasHeader('Authorization'))->toBeFalse();

    $token = RegistryServiceFake::body('instances-register-cloud')['token'];
    expect(RecordingInstanceRegistration::$settings[InstanceRegistration::SETTING_INSTANCE_TOKEN])->toBe('enc:' . base64_encode(strrev($token)))
        ->and(config('registry-bridge.registry.instance_token'))->toBe($token)
        ->and(RegistryConfig::current()->instanceKind())->toBe('cloud');
});

test('rotates onto the instance the import prepared', function () {
    bootCloud($this->service);
    $this->service->on('POST', 'v1/instances/register/cloud', 'instances-register-cloud-rotated');

    $result = CloudRegistration::ensure();

    expect($result['status'])->toBe(CloudRegistration::STATUS_ROTATED)
        ->and($result['message'])->toStartWith('Re-registered');
});

test('keeps a stored token the service still accepts: nothing is sent', function () {
    bootCloud($this->service, [], storedCloudRegistration('flbi_stored'));
    $this->service->on('GET', 'v1/instances/me', 'instances-me-cloud-tenant');

    expect(CloudRegistration::ensure()['status'])->toBe(CloudRegistration::STATUS_CURRENT)
        ->and($this->service->requestsTo('POST', 'v1/instances/register/cloud'))->toBe([])
        ->and($this->service->requestsTo('GET', 'v1/instances/me')[0]->header('Authorization')[0])->toBe('Bearer flbi_stored');
});

test('registers again with the refused token as previous_token', function () {
    bootCloud($this->service, [], storedCloudRegistration('flbi_rotated_elsewhere'));
    $this->service->on('GET', 'v1/instances/me', 'instances-me-unauthenticated')
        ->on('POST', 'v1/instances/register/cloud', 'instances-register-cloud-rotated');

    expect(CloudRegistration::ensure()['status'])->toBe(CloudRegistration::STATUS_ROTATED);

    $body = json_decode($this->service->requestsTo('POST', 'v1/instances/register/cloud')[0]->body(), true);
    expect($body['previous_token'])->toBe('flbi_rotated_elsewhere');
});

test('never registers while the service cannot check the token', function (int $status) {
    bootCloud($this->service, [], storedCloudRegistration('flbi_stored'));
    $this->service->respondWith('GET', 'v1/instances/me', $status, ['errors' => ['Down for maintenance']]);

    expect(CloudRegistration::ensure()['status'])->toBe(CloudRegistration::STATUS_UNAVAILABLE)
        ->and($this->service->requestsTo('POST', 'v1/instances/register/cloud'))->toBe([]);
})->with([500, 503, 599]);

test('a second replica finds the token the first one stored and does not rotate it', function () {
    bootCloud($this->service);
    $this->service->on('POST', 'v1/instances/register/cloud', 'instances-register-cloud')
        ->on('GET', 'v1/instances/me', 'instances-me-cloud-tenant');

    CloudRegistration::ensure();

    // Another process: the same settings table and cache, its own config.
    $stored = RecordingInstanceRegistration::$settings;
    RegistrySettings::flush();
    bootCloud($this->service, [], $stored);

    expect(CloudRegistration::ensure()['status'])->toBe(CloudRegistration::STATUS_CURRENT)
        ->and($this->service->requestsTo('POST', 'v1/instances/register/cloud'))->toHaveCount(1);
});

test('--force rotates an accepted token', function () {
    $container = bootCloud($this->service, [], storedCloudRegistration('flbi_stored'));
    $this->service->on('GET', 'v1/instances/me', 'instances-me-cloud-tenant')
        ->on('POST', 'v1/instances/register/cloud', 'instances-register-cloud-rotated');

    [$code, $output] = runCloudCommand($container, new RegisterCloudInstance(), ['--force' => true, '--json' => true]);

    expect($code)->toBe(0)
        ->and(json_decode($output, true)['status'])->toBe('rotated')
        ->and($output)->not->toContain(RegistryServiceFake::body('instances-register-cloud-rotated')['token'])
        ->and(json_decode($output, true)['summary']['token_hint'])->toBe('flbi_…0000');
});

test('does nothing unless auto-registration and the secret are both set', function (array $cloud) {
    $container = bootCloud($this->service, ['cloud' => $cloud]);

    expect(CloudRegistration::ensure()['status'])->toBe(CloudRegistration::STATUS_DISABLED)
        ->and($this->service->requests)->toBe([])
        ->and(RegistryConfig::current()->instanceKind())->toBe('self_hosted');

    [$code, $output] = runCloudCommand($container, new Initialize());
    expect($code)->toBe(0)->and($output)->toContain('Nothing to initialize');
})->with([
    'off'       => [['auto_register' => false, 'platform_secret' => CLOUD_SECRET]],
    'no secret' => [['auto_register' => true, 'platform_secret' => '']],
]);

test('a refused registration fails the command but never the boot', function () {
    $container = bootCloud($this->service);
    $this->service->on('POST', 'v1/instances/register/cloud', 'instances-register-cloud-unsigned');

    expect(fn () => CloudRegistration::ensure())->toThrow(RegistryServiceException::class, 'The request is not signed with the platform secret.');

    [$code, $output] = runCloudCommand($container, new RegisterCloudInstance());
    expect($code)->toBe(1)->and($output)->toContain('The request is not signed with the platform secret.');

    [$code, $output] = runCloudCommand($container, new Initialize());
    expect($code)->toBe(0)->and($output)->toContain('Cloud registration with the Registry Service failed')->and($output)->toContain('retries it every five minutes');
});

test('registry:init registers on boot', function () {
    $container = bootCloud($this->service);
    $this->service->on('POST', 'v1/instances/register/cloud', 'instances-register-cloud');

    [$code, $output] = runCloudCommand($container, new Initialize());

    expect($code)->toBe(0)->and($output)->toContain('Registered Fleetbase Cloud');
});

test('the signature matches the one the service computes', function () {
    $headers = CloudRegistration::signatureHeaders('secret', '{"host":"x"}', 1700000000, 'abcdefabcdefabcdef');

    expect($headers)->toBe([
        'X-Registry-Timestamp' => '1700000000',
        'X-Registry-Nonce'     => 'abcdefabcdefabcdef',
        'X-Registry-Signature' => 'v1=' . hash_hmac('sha256', '1700000000.abcdefabcdefabcdef.{"host":"x"}', 'secret'),
    ]);
});
