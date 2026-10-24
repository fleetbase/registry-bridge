<?php

use Fleetbase\RegistryBridge\Support\Installer\Artifact;
use Fleetbase\RegistryBridge\Support\Installer\ArtifactDownloader;
use Fleetbase\RegistryBridge\Support\Installer\Checksum;
use Fleetbase\RegistryBridge\Support\Installer\EngineRelease;
use Fleetbase\RegistryBridge\Support\Installer\Files;
use Fleetbase\RegistryBridge\Support\Installer\InstallerException;
use Fleetbase\RegistryBridge\Support\Installer\NodeEngineCompat;
use Fleetbase\RegistryBridge\Support\Installer\OutputProgress;
use Fleetbase\RegistryBridge\Support\Installer\PhpEngineCompat;
use Fleetbase\RegistryBridge\Support\Installer\ProcessResult;
use Fleetbase\RegistryBridge\Support\Installer\RuntimeEngineManifest;
use Fleetbase\RegistryBridge\Tests\Installer\Fakes\FakeProcessRunner;
use Fleetbase\RegistryBridge\Tests\Installer\Fakes\InstallerSandbox;

beforeEach(function () {
    $this->sandbox = new InstallerSandbox();
});

afterEach(function () {
    $this->sandbox->cleanup();
});

// The cases mirror @fleetbase/ember-core node-tests for lib/engine-compat.js (ember-core#99).
test('the PHP compat check refuses missing modules and a different ember-ui minor', function () {
    $host     = ['versions' => ['@fleetbase/ember-ui' => '0.4.6', '@fleetbase/ember-core' => '0.3.26', 'ember-source' => '5.4.0'], 'modules' => ['a/index', 'b']];
    $manifest = ['builtAgainst' => ['@fleetbase/ember-ui' => '0.4.9', '@fleetbase/ember-core' => '0.3.26', 'ember-source' => '5.8.0'], 'requires' => ['modules' => ['a', 'b', 'c']]];

    $minor = PhpEngineCompat::compare($manifest, $host, 'minor');
    expect($minor->compatible)->toBeFalse();
    expect($minor->missing)->toBe(['c']);
    expect($minor->reasons)->toBe(['the console does not define 1 module(s) the engine needs']);
    expect($minor->versions[0])->toBe(['package' => '@fleetbase/ember-ui', 'built' => '0.4.9', 'host' => '0.4.6', 'ok' => true, 'note' => 'differs, allowed by policy']);
    expect($minor->versions[2]['ok'])->toBeTrue('recorded packages only need the same major');

    $manifest['requires']['modules'] = ['a', 'b'];
    expect(PhpEngineCompat::compare($manifest, $host, 'minor')->compatible)->toBeTrue();
    expect(PhpEngineCompat::compare($manifest, $host, 'exact')->reasons)->toBe(['@fleetbase/ember-ui 0.4.9 (built against) is not compatible with 0.4.6 (console)']);

    $manifest['builtAgainst']['@fleetbase/ember-core'] = '0.4.0';
    expect(PhpEngineCompat::compare($manifest, $host, 'minor')->compatible)->toBeFalse();
    expect(PhpEngineCompat::compare($manifest, $host, 'modules')->compatible)->toBeTrue();

    $manifest['builtAgainst']['ember-source'] = '6.0.0';
    expect(PhpEngineCompat::compare($manifest, $host, 'modules')->compatible)->toBeFalse();

    $unknown = PhpEngineCompat::compareVersion('@fleetbase/ember-ui', null, '0.4.6', 'minor');
    expect($unknown)->toMatchArray(['ok' => true, 'note' => 'unknown version, not compared']);
});

test('the Node compat driver parses the CLI JSON and treats other exit codes as errors', function () {
    $runner = new FakeProcessRunner();
    $runner->on(['node'], fn () => new ProcessResult(0, "{\n  \"name\": \"x\",\n  \"compatible\": true,\n  \"missing\": [],\n  \"versions\": [],\n  \"reasons\": []\n}\n"));
    $compat = new NodeEngineCompat($runner, '/ember-core/bin/check-engine-compat.js', '/console');

    expect($compat->check('/m.json', '/h.json', 'exact')->compatible)->toBeTrue();
    expect($runner->commands()[0])->toBe('node /ember-core/bin/check-engine-compat.js --manifest /m.json --host /h.json --policy exact --json');

    $runner->on(['node'], fn () => new ProcessResult(2, 'Usage: check-engine-compat ...'));
    expect(fn () => $compat->check('/m.json', '/h.json'))->toThrow(RuntimeException::class, 'check-engine-compat failed (exit 2)');
});

test('the runtime manifest entry is the release runtime block plus name, version, baseUrl, manifest and requires', function () {
    $manifest = new RuntimeEngineManifest($this->sandbox->root . '/runtime/runtime-engines.json', '/engines-dist');
    expect($manifest->toResponse())->toBe(['engines' => []]);

    $release = ['name' => '@fleetbase/pallet-engine', 'version' => '0.0.2', 'runtime' => ['mount' => ['as' => 'pallet', 'path' => 'pallet', 'root' => false], 'bundle' => ['assets' => [['uri' => 'assets/engine.js', 'type' => 'js']], 'dependencies' => []], 'config' => ['modulePrefix' => '@fleetbase/pallet-engine'], 'routes' => 'routes.js', 'hostApp' => 'host-app.js', 'translations' => 'translations.json', 'extension' => 'extension.js'], 'requires' => ['modules' => ['@fleetbase/ember-ui/components/button']], 'files' => ['routes.js' => 'sha256-x']];

    $entry = $manifest->put($release);
    expect(array_keys($entry))->toBe(['name', 'version', 'baseUrl', 'mount', 'bundle', 'config', 'routes', 'hostApp', 'translations', 'extension', 'manifest', 'requires']);
    expect($entry['baseUrl'])->toBe('/engines-dist/@fleetbase/pallet-engine/');
    expect($entry['manifest'])->toBe('manifest.json');

    $manifest->put(['name' => '@acme/alpha-engine', 'version' => '1.0.0', 'runtime' => ['bundle' => ['assets' => [['uri' => 'a.js']]], 'routes' => 'routes.js']]);
    $manifest->put([...$release, 'version' => '0.0.3']);
    expect(array_column($manifest->all(), 'version', 'name'))->toBe(['@acme/alpha-engine' => '1.0.0', '@fleetbase/pallet-engine' => '0.0.3']);

    expect($manifest->remove('@acme/alpha-engine'))->toBeTrue();
    expect($manifest->remove('@acme/alpha-engine'))->toBeFalse();
    expect(json_decode(file_get_contents($manifest->file()), true)['schema'])->toBe(1);
    expect(fn () => $manifest->put(['name' => '../../etc']))->toThrow(InstallerException::class);
});

test('prebuilt releases unpack from tar.gz and zip archives', function (string $format) {
    $archive = $this->sandbox->buildDist('1.0.0', format: $format);

    $release = EngineRelease::extract($archive, $this->sandbox->root . '/x', InstallerSandbox::ENGINE);

    expect($release->name())->toBe(InstallerSandbox::ENGINE);
    expect($release->version())->toBe('1.0.0');
    expect($release->path)->toEndWith('/x/package');
    expect(is_file($release->manifestPath()))->toBeTrue();
})->with(['tgz', 'zip']);

test('a release is refused when its files do not match the manifest', function (callable $tamper, string $message) {
    $archive = $this->sandbox->buildDist('1.0.0', tamper: $tamper);

    expect(fn () => EngineRelease::extract($archive, $this->sandbox->root . '/x', InstallerSandbox::ENGINE))->toThrow(RuntimeException::class, $message);
})->with([
    'changed file'    => [fn (string $dir) => file_put_contents($dir . '/routes.js', 'evil()'), 'Checksum mismatch for routes.js'],
    'missing file'    => [fn (string $dir) => unlink($dir . '/host-app.js'), 'missing host-app.js'],
    'unlisted file'   => [fn (string $dir) => file_put_contents($dir . '/assets/extra.js', 'x'), 'files its manifest does not list: assets/extra.js'],
]);

test('a release for another package or without a runtime entry is refused', function () {
    $other = $this->sandbox->buildDist('1.0.0', ['name' => '@acme/other-engine']);
    expect(fn () => EngineRelease::extract($other, $this->sandbox->root . '/x', InstallerSandbox::ENGINE))->toThrow(RuntimeException::class, 'is for "@acme/other-engine"');

    $noRuntime = $this->sandbox->buildDist('1.0.0', ['runtime' => ['routes' => null]]);
    expect(fn () => EngineRelease::extract($noRuntime, $this->sandbox->root . '/y', InstallerSandbox::ENGINE))->toThrow(RuntimeException::class, 'no runtime entry');
});

test('archives with paths outside the release are refused', function () {
    $archive = $this->sandbox->root . '/evil.zip';
    $zip     = new ZipArchive();
    $zip->open($archive, ZipArchive::CREATE);
    $zip->addFromString('package/manifest.json', '{}');
    $zip->addFromString('../escape.txt', 'x');
    $zip->close();

    expect(fn () => EngineRelease::extract($archive, $this->sandbox->root . '/x', InstallerSandbox::ENGINE))->toThrow(RuntimeException::class, 'unsafe path');
    expect(file_exists($this->sandbox->root . '/escape.txt'))->toBeFalse();
});

test('checksums accept sha256 hex, sha512 base64 integrity strings and bare digests', function () {
    $file = $this->sandbox->root . '/a.txt';
    Files::write($file, 'hello');

    expect(Checksum::matches($file, 'sha256-' . hash('sha256', 'hello')))->toBeTrue();
    expect(Checksum::matches($file, 'sha256:' . strtoupper(hash('sha256', 'hello'))))->toBeTrue();
    expect(Checksum::matches($file, hash('sha256', 'hello')))->toBeTrue();
    expect(Checksum::matches($file, 'sha512-' . base64_encode(hash('sha512', 'hello', true))))->toBeTrue();
    expect(Checksum::matches($file, 'sha256-' . hash('sha256', 'other')))->toBeFalse();
    expect(fn () => Checksum::matches($file, 'md5-abc'))->toThrow(InvalidArgumentException::class);
});

test('the downloader copies local and disk artifacts and enforces checksums when required', function () {
    $source = $this->sandbox->root . '/release.tgz';
    Files::write($source, 'archive');

    $downloader = new ArtifactDownloader(false, fn (string $disk, string $path) => $disk === 's3' && $path === 'releases/a.tgz' ? fopen($source, 'rb') : null);
    expect(file_get_contents($downloader->download(new Artifact('dist', 'file://' . $source), $this->sandbox->root . '/dl')))->toBe('archive');
    expect(file_get_contents($downloader->download(new Artifact('dist', 'disk://s3/releases/a.tgz', Checksum::sha256($source)), $this->sandbox->root . '/dl')))->toBe('archive');
    expect(fn () => $downloader->download(new Artifact('dist', 'relative/path.tgz'), $this->sandbox->root . '/dl'))->toThrow(RuntimeException::class, 'does not exist');

    $strict = new ArtifactDownloader(true);
    expect(fn () => $strict->download(new Artifact('dist', $source), $this->sandbox->root . '/dl'))->toThrow(RuntimeException::class, 'checksums are required');
});

test('output progress still maps composer, pnpm and build lines', function () {
    expect(OutputProgress::composerInstallOutputProgress('  - Downloading acme/widgets (1.0.0)'))->toBe(75);
    expect(OutputProgress::pnpmInstallOutputProgress('Progress: resolved 1200, reused 1200'))->toBe(60);
    expect(OutputProgress::pnpmBuildOutputProgress('Built project successfully. Stored in "dist/".'))->toBe(100);
    expect(OutputProgress::composerOutputFriendly("Problem 1\n - x"))->toBe('Unable to install due to dependency compatibility issues.');
});
