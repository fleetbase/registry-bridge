<?php

use Fleetbase\RegistryBridge\Support\Installer\ExtensionsManifest;
use Fleetbase\RegistryBridge\Support\Installer\Files;
use Fleetbase\RegistryBridge\Support\Installer\InstallerConfig;
use Fleetbase\RegistryBridge\Support\Installer\InstallerException;

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/extensions-manifest-test-' . bin2hex(random_bytes(6));
    Files::write($this->dir . '/composer.json', <<<'JSON'
        {
            "name": "fleetbase/extensions",
            "require": {
                "fleetbase/core-platform": "*",
                "fleetbase/fleetops-api": "^0.6.74"
            },
            "repositories": {
                "core-platform": {
                    "type": "path",
                    "url": "core-platform",
                    "options": {
                        "symlink": false
                    }
                }
            },
            "config": {
                "allow-plugins": {},
                "sort-packages": true
            },
            "scripts": {}
        }
        JSON);
    $this->manifest = ExtensionsManifest::find($this->dir . '/');
});

afterEach(function () {
    Files::delete($this->dir);
});

test('find returns the manifest only where a composer.json exists', function () {
    expect($this->manifest)->toBeInstanceOf(ExtensionsManifest::class)
        ->and($this->manifest->directory)->toBe($this->dir)
        ->and($this->manifest->file())->toBe($this->dir . '/composer.json')
        ->and($this->manifest->lockFile())->toBe($this->dir . '/composer.lock')
        ->and(ExtensionsManifest::find($this->dir . '/missing'))->toBeNull();
});

test('require adds the package in sort-packages order and replaces an existing entry', function () {
    // A hand-edited manifest may list platform packages; Composer keeps those first.
    file_put_contents($this->manifest->file(), '{"require": {"fleetbase/core-platform": "*", "fleetbase/fleetops-api": "^0.6.74", "ext-gmp": "*"}}');

    $this->manifest->require('acme/widgets-api', '1.0.0');
    $this->manifest->require('fleetbase/fleetops-api', '^0.6.80');
    $this->manifest->require('acme/unconstrained-api', null);

    expect($this->manifest->read()['require'])->toBe([
        'ext-gmp'                 => '*',
        'acme/unconstrained-api'  => '*',
        'acme/widgets-api'        => '1.0.0',
        'fleetbase/core-platform' => '*',
        'fleetbase/fleetops-api'  => '^0.6.80',
    ])
        ->and($this->manifest->requires('Fleetbase/FleetOps-API'))->toBeTrue('lookups ignore case, as Composer does')
        ->and($this->manifest->constraint('ACME/widgets-api'))->toBe('1.0.0')
        ->and($this->manifest->constraint('acme/other-api'))->toBeNull();
});

test('the file keeps Composer\'s layout and every section it did not change', function () {
    $this->manifest->require('acme/widgets-api', '^1.0');
    $contents = (string) file_get_contents($this->manifest->file());

    expect($contents)->toEndWith("}\n")
        ->toContain("\n    \"name\": \"fleetbase/extensions\",")
        ->toContain('"url": "core-platform"')
        // json_decode(…, true) reads {} as []; Composer rejects "allow-plugins": [].
        ->toContain('"allow-plugins": {}')
        ->toContain('"scripts": {}')
        ->and(glob($this->dir . '/*.tmp'))->toBe([]);
});

test('remove drops the package and leaves an empty require as an object', function () {
    $this->manifest->remove('fleetbase/fleetops-api');
    expect($this->manifest->read()['require'])->toBe(['fleetbase/core-platform' => '*']);

    $manifest = new ExtensionsManifest($this->dir);
    file_put_contents($manifest->file(), '{"require": {"acme/only-api": "1.0.0"}}');
    $manifest->remove('acme/only-api');
    expect((string) file_get_contents($manifest->file()))->toContain('"require": {}');
});

test('core-platform is never required or removed by the installer', function (string $method) {
    expect(fn () => $this->manifest->{$method}('fleetbase/core-platform', '1.0.0'))
        ->toThrow(InstallerException::class, 'describes the packages core installs');
    expect($this->manifest->constraint('fleetbase/core-platform'))->toBe('*');
})->with(['require', 'remove']);

test('unsafe package names and constraints are refused before anything is written', function () {
    $before = file_get_contents($this->manifest->file());

    expect(fn () => $this->manifest->require('../../etc', '1.0.0'))->toThrow(InstallerException::class, 'not a valid Composer package name')
        ->and(fn () => $this->manifest->require('acme/widgets-api', '1.0.0; rm -rf /'))->toThrow(InstallerException::class, 'not a valid version constraint')
        ->and(file_get_contents($this->manifest->file()))->toBe($before);
});

test('setRepository adds or replaces a named repository', function () {
    $this->manifest->setRepository('fleetbase-registry-service', ['name' => 'ignored', 'type' => 'composer', 'url' => 'https://registry.fleetbase.io/composer', 'canonical' => false]);
    $this->manifest->setRepository('fleetbase-registry-service', ['type' => 'composer', 'url' => 'https://registry.example.com/composer', 'canonical' => false]);

    expect($this->manifest->read()['repositories'])->toBe([
        'core-platform'              => ['type' => 'path', 'url' => 'core-platform', 'options' => ['symlink' => false]],
        'fleetbase-registry-service' => ['type' => 'composer', 'url' => 'https://registry.example.com/composer', 'canonical' => false],
    ]);
});

test('setRepository turns a list of repositories into named entries', function () {
    file_put_contents($this->manifest->file(), '{"repositories": [{"name": "core-platform", "type": "path", "url": "core-platform"}, {"type": "vcs", "url": "https://git.example.com/x.git"}, "packagist.org: false"]}');

    $this->manifest->setRepository('fleetbase-registry-service', ['type' => 'composer', 'url' => 'https://registry.fleetbase.io/composer']);

    expect($this->manifest->read()['repositories'])->toBe([
        'core-platform'              => ['type' => 'path', 'url' => 'core-platform'],
        'repository-1'               => ['type' => 'vcs', 'url' => 'https://git.example.com/x.git'],
        'repository-2'               => 'packagist.org: false',
        'fleetbase-registry-service' => ['type' => 'composer', 'url' => 'https://registry.fleetbase.io/composer'],
    ]);
});

test('setRepository refuses names it could break the manifest with', function (string $name) {
    expect(fn () => $this->manifest->setRepository($name, ['type' => 'composer', 'url' => 'https://x.example.com']))
        ->toThrow(InstallerException::class, 'is not a repository name the installer may set');
})->with(['core-platform', 'Bad Name', '../x']);

test('a manifest that is not JSON is reported, not overwritten', function () {
    file_put_contents($this->manifest->file(), 'not json');

    expect(fn () => $this->manifest->require('acme/widgets-api', '1.0.0'))->toThrow(RuntimeException::class, 'is not valid JSON')
        ->and(file_get_contents($this->manifest->file()))->toBe('not json');
});

test('composerObjects restores only the sections Composer requires to be objects', function () {
    expect(json_encode(ExtensionsManifest::composerObjects(['require' => [], 'extra' => [], 'config' => ['allow-plugins' => [], 'platform' => [], 'preferred-install' => 'dist'], 'repositories' => [], 'keywords' => []])))
        ->toBe('{"require":{},"extra":{},"config":{"allow-plugins":{},"platform":{},"preferred-install":"dist"},"repositories":[],"keywords":[]}')
        ->and(json_encode(ExtensionsManifest::composerObjects(['config' => false])))->toBe('{"config":false}');
});

test('the manifest folder defaults to <api>/extensions and can be set', function () {
    expect(InstallerConfig::fromArray(['api_path' => '/fleetbase/api/'])->extensionsDirectory())->toBe('/fleetbase/api/extensions')
        ->and(InstallerConfig::fromArray(['api_path' => '/fleetbase/api', 'extensions_path' => '/data/extensions/'])->extensionsDirectory())->toBe('/data/extensions')
        ->and(InstallerConfig::fromArray(['api_path' => '/fleetbase/api', 'extensions_path' => ''])->extensionsDirectory())->toBe('/fleetbase/api/extensions');
});
