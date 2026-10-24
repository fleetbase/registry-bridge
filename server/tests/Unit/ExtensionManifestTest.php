<?php

use Fleetbase\RegistryBridge\Support\ExtensionManifest;

function validManifest(array $overrides = []): array
{
    return array_merge([
        'name'        => 'Registry Bridge',
        'version'     => '0.1.10',
        'description' => 'Internal Bridge between Fleetbase API and Extensions Registry',
        'engine'      => 'package.json',
        'api'         => 'composer.json',
    ], $overrides);
}

test('schema file is valid JSON Schema 2020-12', function () {
    $schema = json_decode((string) file_get_contents(ExtensionManifest::schemaPath()), true);

    expect($schema)->toBeArray()
        ->and($schema['$schema'])->toBe('https://json-schema.org/draft/2020-12/schema')
        ->and($schema['required'])->toBe(['name', 'version']);
});

test('accepts the manifest shape existing extensions ship', function () {
    expect(ExtensionManifest::validate(validManifest()))->toBe([]);
});

test('accepts package names for engine and api', function () {
    $manifest = validManifest(['engine' => '@fleetbase/fleetops-engine', 'api' => 'fleetbase/fleetops-api']);

    expect(ExtensionManifest::validate($manifest))->toBe([]);
});

test('accepts compat ranges, channel and keywords', function () {
    $manifest = validManifest([
        'compat'   => ['console' => '>=0.7 <1.0', 'api' => '^1.6'],
        'channel'  => 'beta',
        'keywords' => ['fleet', 'routing'],
    ]);

    expect(ExtensionManifest::validate($manifest))->toBe([]);
});

test('accepts a stdClass manifest as decoded from extension.json', function () {
    $manifest = json_decode((string) json_encode(validManifest()));

    expect(ExtensionManifest::validate($manifest))->toBe([]);
});

test('rejects a non semver version', function () {
    $errors = ExtensionManifest::validate(validManifest(['version' => 'v1']));

    expect($errors)->toHaveKey('/version');
});

test('requires name and version', function () {
    $errors = ExtensionManifest::validate(['engine' => 'package.json']);

    expect($errors)->not->toBe([])
        ->and(implode(' ', ExtensionManifest::flatten($errors)))->toContain('name');
});

test('requires engine or api', function () {
    $errors = ExtensionManifest::validate(['name' => 'Nothing', 'version' => '1.0.0']);

    expect($errors)->not->toBe([]);
});

test('rejects an unknown channel and a malformed compat block', function () {
    $errors = ExtensionManifest::validate(validManifest(['channel' => 'nightly']));
    expect($errors)->toHaveKey('/channel');

    $errors = ExtensionManifest::validate(validManifest(['compat' => ['web' => '1.0']]));
    expect($errors)->toHaveKey('/compat');
});

test('rejects duplicate keywords', function () {
    $errors = ExtensionManifest::validate(validManifest(['keywords' => ['a', 'a']]));

    expect($errors)->toHaveKey('/keywords');
});

test('flatten produces pointer prefixed lines', function () {
    $lines = ExtensionManifest::flatten(['/version' => ['bad version'], '/' => ['missing']]);

    expect($lines)->toBe(['/version: bad version', '/: missing']);
});
