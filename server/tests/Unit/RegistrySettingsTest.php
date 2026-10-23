<?php

use Fleetbase\RegistryBridge\Providers\RegistryBridgeServiceProvider;
use Fleetbase\RegistryBridge\Support\InstanceRegistration;
use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Fleetbase\RegistryBridge\Support\RegistrySettings;
use Fleetbase\RegistryBridge\Tests\Support\StoredRegistrySettings;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessing;

function settingsEncrypter(): StringEncrypter
{
    return new class() implements StringEncrypter {
        public function encryptString($value)
        {
            return 'enc:' . base64_encode(strrev($value));
        }

        public function decryptString($payload)
        {
            if (!str_starts_with($payload, 'enc:')) {
                throw new RuntimeException('The payload is invalid.');
            }

            return strrev((string) base64_decode(substr($payload, 4)));
        }
    };
}

/**
 * Settings as `InstanceRegistration::store()` leaves them for a registration with the given token.
 *
 * @return array<string, mixed>
 */
function storedRegistration(string $token, string $id, string $serviceUrl = 'https://registry.example.test'): array
{
    return [
        InstanceRegistration::SETTING_SERVICE_URL    => $serviceUrl,
        InstanceRegistration::SETTING_INSTANCE_TOKEN => settingsEncrypter()->encryptString($token),
        InstanceRegistration::SETTING_INSTANCE       => ['id' => $id, 'service_url' => $serviceUrl],
    ];
}

beforeEach(function () {
    RegistrySettings::flush();
    StoredRegistrySettings::$stored = [];
    StoredRegistrySettings::$reads  = 0;

    $this->container = new Container();
    $this->container->instance('config', new Config([
        'registry-bridge' => ['registry' => ['instance_kind' => 'self_hosted', 'instance' => []]],
    ]));
    $this->container->instance('encrypter', settingsEncrypter());
    $this->container->instance('events', new Dispatcher($this->container));
    $this->container->instance(RegistrySettings::class, new StoredRegistrySettings());
    Container::setInstance($this->container);
});

afterEach(function () {
    RegistrySettings::flush();
    Container::setInstance(null);
});

test('stored settings override the config file and missing ones fall back to it', function () {
    $values = RegistrySettings::merge(
        ['service_url' => 'https://env.example.test', 'instance_token' => null],
        [
            InstanceRegistration::SETTING_SERVICE_URL    => '',
            InstanceRegistration::SETTING_INSTANCE_TOKEN => 'not-encrypted',
            InstanceRegistration::SETTING_INSTANCE       => ['id' => 'inst_1'],
        ],
        settingsEncrypter()
    );

    expect($values)->toBe([
        'service_url'    => 'https://env.example.test',
        'instance_token' => null,
        'instance'       => ['id' => 'inst_1'],
    ]);
});

test('the settings are read once per request and keep the rest of the registry config', function () {
    StoredRegistrySettings::$stored = storedRegistration('flbi_first', 'inst_first');

    $config = RegistryConfig::current();
    RegistryConfig::current();

    expect(StoredRegistrySettings::$reads)->toBe(1)
        ->and($config->instanceToken())->toBe('flbi_first')
        ->and($config->serviceUrl())->toBe('https://registry.example.test')
        ->and($config->instance()['id'])->toBe('inst_first')
        ->and($config->instanceKind())->toBe('self_hosted');
});

test('an Octane request sees a registration another process stored after the worker booted', function () {
    // The worker boots with the first registration
    StoredRegistrySettings::$stored = storedRegistration('flbi_first', 'inst_first');
    $provider                       = new RegistryBridgeServiceProvider($this->container);
    RegistrySettings::instance()->apply();
    $provider->refreshSettingsPerOperation();
    expect(RegistryConfig::current()->instanceToken())->toBe('flbi_first');

    // `php artisan registry:register` rotates the token in the settings table
    StoredRegistrySettings::$stored = storedRegistration('flbi_second', 'inst_first');
    expect(RegistryConfig::current()->instanceToken())->toBe('flbi_first');

    // Octane clones the booted application and its config for the next request
    $sandbox = clone $this->container;
    $sandbox->instance('config', clone $this->container['config']);
    Container::setInstance($sandbox);
    $this->container['events']->dispatch('Laravel\Octane\Events\RequestReceived', [(object) ['app' => $this->container, 'sandbox' => $sandbox]]);

    expect(RegistryConfig::current()->instanceToken())->toBe('flbi_second')
        ->and(StoredRegistrySettings::$reads)->toBe(2)
        // The booted application is untouched; the request after this one reads the settings again
        ->and($this->container->bound(RegistrySettings::APPLIED))->toBeTrue()
        ->and($this->container['config']->get('registry-bridge.registry.instance_token'))->toBe('flbi_first');
});

test('a forgotten registration falls back to the config file on the next request', function () {
    StoredRegistrySettings::$stored = storedRegistration('flbi_first', 'inst_first');
    expect(RegistryConfig::current()->isRegistered())->toBeTrue();

    StoredRegistrySettings::$stored = [InstanceRegistration::SETTING_SERVICE_URL => 'https://registry.example.test'];
    RegistrySettings::instance()->reset();

    $config = RegistryConfig::current();
    expect($config->isRegistered())->toBeFalse()
        ->and($config->instance())->toBe([])
        ->and($config->serviceUrl())->toBe('https://registry.example.test');
});

test('each queued job reads the settings again', function () {
    StoredRegistrySettings::$stored = storedRegistration('flbi_first', 'inst_first');
    (new RegistryBridgeServiceProvider($this->container))->refreshSettingsPerOperation();
    expect(RegistryConfig::current()->instanceToken())->toBe('flbi_first');

    StoredRegistrySettings::$stored = storedRegistration('flbi_second', 'inst_first');
    $this->container['events']->dispatch(JobProcessing::class);

    expect(RegistryConfig::current()->instanceToken())->toBe('flbi_second');
});

test('without a readable settings table the config is left as it is', function () {
    StoredRegistrySettings::$stored = null;

    $config = RegistryConfig::current();

    expect($config->isRegistered())->toBeFalse()
        ->and($config->instanceKind())->toBe('self_hosted')
        ->and(StoredRegistrySettings::$reads)->toBe(1);
});
