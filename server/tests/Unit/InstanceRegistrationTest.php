<?php

use Fleetbase\RegistryBridge\Support\InstanceRegistration;
use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Fleetbase\RegistryBridge\Tests\Support\RegistryServiceFake;
use Illuminate\Contracts\Encryption\StringEncrypter;

function reversingEncrypter(): StringEncrypter
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

test('the register payload carries name, host, kind and versions', function () {
    expect(InstanceRegistration::payload(' w9qa-sc8r-37jt-9pa8 ', 'Acme Fleet', 'https://fleet.example.test', 'self_hosted', 'v0.7.69', '1.6.30'))->toBe([
        'code'            => 'W9QA-SC8R-37JT-9PA8',
        'name'            => 'Acme Fleet',
        'kind'            => 'self_hosted',
        'host'            => 'https://fleet.example.test',
        'console_version' => '0.7.69',
        'api_version'     => '1.6.30',
    ]);
});

test('a registered instance sends its current token so the service rotates it', function () {
    expect(InstanceRegistration::payload('CODE', 'Acme', null, 'self_hosted', null, null, ' flbi_previous '))->toBe([
        'code'           => 'CODE',
        'name'           => 'Acme',
        'kind'           => 'self_hosted',
        'previous_token' => 'flbi_previous',
    ]);
});

test('the previous token only goes back to the service that issued it', function () {
    $config = new RegistryConfig(['service_url' => 'https://registry.example.test/', 'instance_token' => 'flbi_previous']);

    expect(InstanceRegistration::previousToken($config, 'registry.example.test'))->toBe('flbi_previous')
        ->and(InstanceRegistration::previousToken($config, 'https://other-registry.example.test'))->toBeNull()
        ->and(InstanceRegistration::previousToken(new RegistryConfig(['instance_token' => 'flbi_previous']), 'https://registry.example.test'))->toBeNull()
        ->and(InstanceRegistration::previousToken(new RegistryConfig(['service_url' => 'https://registry.example.test']), 'https://registry.example.test'))->toBeNull();
});

test('unknown versions and hosts are left out of the payload', function () {
    expect(InstanceRegistration::payload('CODE', '', null, 'cloud', 'dev-main', null))->toBe([
        'code' => 'CODE',
        'name' => 'Fleetbase',
        'kind' => 'cloud',
    ]);
});

test('the stored summary keeps what the service returned but never the token', function () {
    $registration = RegistryServiceFake::body('instances-register');

    $summary = InstanceRegistration::instanceSummary($registration['instance'], RegistryServiceFake::SERVICE_URL, $registration['token'], '2026-10-22T07:09:47+00:00');

    expect($summary)->toMatchArray([
        'id'          => 'inst_rsmdtgevid',
        'name'        => 'Acme Fleet (self-hosted)',
        'kind'        => 'self_hosted',
        'service_url' => RegistryServiceFake::SERVICE_URL,
        'token_hint'  => 'flbi_…0000',
    ])
        ->and($summary['organization'])->toBe(['id' => 'org_qqymqcyvgv', 'slug' => 'registry-demo-buyer', 'name' => 'Registry Demo Buyer'])
        ->and(json_encode($summary))->not->toContain($registration['token']);
});

test('tokens are masked to their prefix and last four characters', function () {
    expect(InstanceRegistration::maskToken('flbi_abcdefghijklmnopqrstuvwxyz0123456789ABCD'))->toBe('flbi_…ABCD')
        ->and(InstanceRegistration::maskToken('plaintoken1234'))->toBe('…1234')
        ->and(InstanceRegistration::maskToken(null))->toBeNull();
});

test('the instance token round-trips through the encrypter and bad payloads are ignored', function () {
    $encrypter = reversingEncrypter();
    $stored    = InstanceRegistration::encryptToken(RegistryServiceFake::INSTANCE_TOKEN, $encrypter);

    expect($stored)->not->toContain(RegistryServiceFake::INSTANCE_TOKEN)
        ->and(InstanceRegistration::decryptToken($stored, $encrypter))->toBe(RegistryServiceFake::INSTANCE_TOKEN)
        ->and(InstanceRegistration::decryptToken('not-encrypted', $encrypter))->toBeNull()
        ->and(InstanceRegistration::decryptToken(null, $encrypter))->toBeNull();
});

test('the console version is read from the console package.json', function () {
    $dir = sys_get_temp_dir() . '/registry-bridge-console-' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir . '/package.json', json_encode(['name' => '@fleetbase/console', 'version' => '0.7.69']));

    try {
        expect(InstanceRegistration::detectConsoleVersion($dir))->toBe('0.7.69')
            ->and(InstanceRegistration::detectConsoleVersion($dir . '/missing'))->toBeNull()
            ->and(InstanceRegistration::cleanVersion('v1.6.30'))->toBe('1.6.30')
            ->and(InstanceRegistration::cleanVersion('dev-main'))->toBeNull();
    } finally {
        unlink($dir . '/package.json');
        rmdir($dir);
    }
});
