<?php

use Fleetbase\RegistryBridge\Support\Installer\Files;
use Fleetbase\RegistryBridge\Support\Installer\ProcessResult;
use Fleetbase\RegistryBridge\Support\Installer\Release;
use Fleetbase\RegistryBridge\Support\Installer\Snapshot;
use Fleetbase\RegistryBridge\Tests\Installer\Fakes\InstallerSandbox;

/*
 * An API with the extensions manifest (fleetbase/fleetbase api/extensions): Composer packages go there,
 * and core's composer.json and composer.lock are never touched.
 */

beforeEach(function () {
    $this->sandbox    = new InstallerSandbox();
    $this->extensions = $this->sandbox->withExtensionsManifest();
    $this->core       = [file_get_contents($this->sandbox->api . '/composer.json'), file_get_contents($this->sandbox->api . '/composer.lock')];
});

afterEach(function () {
    $this->sandbox->cleanup();
});

function coreFiles(InstallerSandbox $sandbox): array
{
    return [file_get_contents($sandbox->api . '/composer.json'), file_get_contents($sandbox->api . '/composer.lock')];
}

function manifestFiles(string $extensions): array
{
    return [file_get_contents($extensions . '/composer.json'), file_get_contents($extensions . '/composer.lock')];
}

test('an install writes the package to the manifest and resolves it there, with core-platform', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('1.0.0');

    $result = $sandbox->installer()->run($sandbox->job());

    expect($result['status'])->toBe('completed');
    expect($result['composer_project'])->toBe($this->extensions);
    expect($sandbox->runner->commands())->toBe([
        'composer update acme/widgets-api fleetbase/core-platform --no-interaction --no-progress --no-audit --no-ansi --no-scripts',
        'php artisan registry:installer-refresh --discover --run=config:clear --run=route:clear --run=view:clear --run=event:clear --run=queue:restart',
    ]);
    expect($sandbox->runner->calls[0]['cwd'])->toBe($this->extensions);
    expect($sandbox->runner->calls[1]['cwd'])->toBe($sandbox->api, 'artisan still runs in the API');

    expect(Files::readJson($this->extensions . '/composer.json')['require'])->toBe(['acme/widgets-api' => '1.0.0', 'fleetbase/core-platform' => '*', 'fleetbase/fleetops-api' => '^0.6.74']);
    expect(Files::readJson($this->extensions . '/composer.lock')['packages'])->toContain('acme/widgets-api');
    expect(coreFiles($sandbox))->toBe($this->core, 'core composer.json and composer.lock are untouched');

    // Composer output still drives the server.install progress the console shows.
    $progress = array_column(array_filter($sandbox->publisher->payloads(), fn ($p) => $p['step'] === 'server.install'), 'progress');
    expect($progress)->toBe([0, 20, 30, 40, 50, 60, 70, 80, 90, 100]);
});

test('the release\'s repository is written to the manifest instead of running composer config', function () {
    $sandbox = $this->sandbox;
    $sandbox->releases->add(new Release($sandbox->extension, '1.0.0', InstallerSandbox::COMPOSER, '^1.0', null, null, [], composerRepository: ['name' => 'fleetbase-registry-service', 'type' => 'composer', 'url' => 'https://registry.fleetbase.io/composer', 'canonical' => false]));

    $result = $sandbox->installer()->run($sandbox->job());

    expect($result['status'])->toBe('completed');
    expect(Files::readJson($this->extensions . '/composer.json')['repositories']['fleetbase-registry-service'])->toBe(['type' => 'composer', 'url' => 'https://registry.fleetbase.io/composer', 'canonical' => false]);
    expect(Files::readJson($this->extensions . '/composer.json')['require']['acme/widgets-api'])->toBe('^1.0');
    expect(collect($sandbox->runner->commands())->contains(fn ($command) => str_contains($command, 'composer config')))->toBeFalse();
    expect(coreFiles($sandbox))->toBe($this->core);
});

test('a composer failure restores the manifest and its lock, and reinstalls the extensions vendor', function () {
    $sandbox = $this->sandbox;
    $sandbox->releases->add(new Release($sandbox->extension, '1.0.0', InstallerSandbox::COMPOSER, '1.0.0', null, null, [], composerRepository: ['name' => 'fleetbase-registry-service', 'type' => 'composer', 'url' => 'https://registry.fleetbase.io/composer']));
    $before = manifestFiles($this->extensions);
    $sandbox->runner->on(['composer', 'update'], function (array $command, string $cwd) {
        // Composer has rewritten the lock when it fails.
        file_put_contents($cwd . '/composer.lock', '{"half": "written"}');

        return new ProcessResult(2, "Problem 1\n  - acme/widgets-api 1.0.0 requires fleetbase/core-api ^2.0 -> fleetbase/core-platform provides 1.6.73");
    });

    $result = $sandbox->installer()->run($sandbox->job());

    expect($result['status'])->toBe('failed');
    expect($result['failed_stage'])->toBe('composer');
    expect(manifestFiles($this->extensions))->toBe($before, 'the repository and the require are gone again');
    expect($result['rollback']['restored'])->toContain($this->extensions . '/composer.json', $this->extensions . '/composer.lock');
    expect($result['rollback']['restored'])->not->toContain($sandbox->api . '/composer.json');

    $install = collect($sandbox->runner->calls)->first(fn ($call) => implode(' ', $call['command']) === 'composer install --no-interaction --no-progress --no-audit --no-ansi --no-scripts');
    expect($install['cwd'])->toBe($this->extensions);
    expect(coreFiles($sandbox))->toBe($this->core);
});

test('a failure after composer succeeded rolls the manifest back too', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('1.0.0');
    $before = manifestFiles($this->extensions);
    $sandbox->runner->on(['php', 'artisan', 'registry:installer-refresh'], 1);

    $result = $sandbox->installer()->run($sandbox->job());

    expect($result['failed_stage'])->toBe('caches');
    expect(manifestFiles($this->extensions))->toBe($before);
    expect(is_dir($sandbox->runtime . '/' . InstallerSandbox::ENGINE))->toBeFalse();
    expect(coreFiles($sandbox))->toBe($this->core);
});

test('a manifest without core-platform resolves just the package', function () {
    $this->extensions = $this->sandbox->withExtensionsManifest(['require' => ['fleetbase/fleetops-api' => '^0.6.74']]);
    $this->sandbox->release('1.0.0', dist: false, composer: true);
    $this->sandbox->runner->on(['pnpm', 'build'], 0);

    $this->sandbox->installer()->run($this->sandbox->job());

    expect($this->sandbox->runner->commands()[0])->toBe('composer update acme/widgets-api --no-interaction --no-progress --no-audit --no-ansi --no-scripts');
});

test('an uninstall removes the package from the manifest and resolves its removal there', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('1.0.0');
    $sandbox->installer()->run($sandbox->job());
    $sandbox->runner->calls = [];

    $result = $sandbox->installer()->run($sandbox->job('uninstall'));

    expect($result['status'])->toBe('completed');
    expect($result['composer_project'])->toBe($this->extensions);
    expect($sandbox->runner->commands()[0])->toBe('composer update acme/widgets-api --no-interaction --no-progress --no-audit --no-ansi --no-scripts');
    expect($sandbox->runner->calls[0]['cwd'])->toBe($this->extensions);
    expect(Files::readJson($this->extensions . '/composer.json')['require'])->toBe(['fleetbase/core-platform' => '*', 'fleetbase/fleetops-api' => '^0.6.74']);
    expect(coreFiles($sandbox))->toBe($this->core);
});

test('a failed uninstall puts the package back in the manifest', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('1.0.0');
    $sandbox->installer()->run($sandbox->job());
    $installed = manifestFiles($this->extensions);
    $sandbox->runner->on(['composer', 'update'], 1);

    $result = $sandbox->installer()->run($sandbox->job('uninstall'));

    expect($result['failed_stage'])->toBe('composer');
    expect(manifestFiles($this->extensions))->toBe($installed);
    expect($sandbox->recorder->installs['company-1'])->toHaveKey('ext-uuid-1');
});

test('an extension still required by core\'s composer.json is not removed by the installer', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('1.0.0');
    // Installed by an installer from before the manifest.
    file_put_contents($sandbox->api . '/composer.json', (string) json_encode(['require' => ['fleetbase/core-api' => '^1.6', InstallerSandbox::COMPOSER => '1.0.0']]));
    $core                                                   = coreFiles($sandbox);
    $sandbox->recorder->installs['company-1']['ext-uuid-1'] = ['version' => '1.0.0'];
    Files::write($sandbox->runtime . '/runtime-engines.json', '{"engines":[]}');

    $result = $sandbox->installer()->run($sandbox->job('uninstall'));

    expect($result['status'])->toBe('completed');
    expect($result['server_package_kept'])->toContain('required by the API\'s own composer.json')->toContain('extensions:migrate-manifest');
    expect(collect($sandbox->runner->commands())->contains(fn ($command) => str_starts_with($command, 'composer')))->toBeFalse();
    expect(coreFiles($sandbox))->toBe($core);
    expect($sandbox->recorder->installs['company-1'])->toBe([]);
});

test('a job interrupted after composer ran is rolled back in the project it ran in', function () {
    $sandbox = $this->sandbox;
    $before  = manifestFiles($this->extensions);
    $paths   = [$this->extensions . '/composer.json', $this->extensions . '/composer.lock'];

    $snapshot = Snapshot::capture($sandbox->root . '/work/job-install/snapshot', $paths);
    $snapshot->flag('composer', $this->extensions);
    file_put_contents($this->extensions . '/composer.json', '{"require":{"acme/widgets-api":"1.0.0"}}');

    $result = $sandbox->installer()->recover($sandbox->job() + ['stage' => 'composer', 'step' => 'server.install']);

    expect($result['status'])->toBe('failed');
    expect(manifestFiles($this->extensions))->toBe($before);
    expect(collect($sandbox->runner->calls)->first(fn ($call) => $call['command'] === ['composer', 'install', '--no-interaction', '--no-progress', '--no-audit', '--no-ansi', '--no-scripts'])['cwd'])->toBe($this->extensions);
});

test('a snapshot left by an installer from before the manifest still rolls back in the API', function () {
    $sandbox  = $this->sandbox;
    $snapshot = Snapshot::capture($sandbox->root . '/work/job-install/snapshot', [$sandbox->api . '/composer.json']);
    $snapshot->flag('composer');

    $sandbox->installer()->recover($sandbox->job() + ['stage' => 'composer']);

    expect(collect($sandbox->runner->calls)->first(fn ($call) => $call['command'] === ['composer', 'install', '--no-interaction', '--no-progress', '--no-audit', '--no-ansi'])['cwd'])->toBe($sandbox->api);
});

test('the installer follows the manifest folder the API binds as path.extensions', function () {
    $sandbox                  = $this->sandbox;
    $elsewhere                = $sandbox->root . '/data/extensions';
    Files::copy($this->extensions . '/composer.json', $elsewhere . '/composer.json');
    Files::delete($this->extensions);
    $sandbox->configOverrides = ['extensions_path' => $elsewhere];
    $sandbox->release('1.0.0');

    $result = $sandbox->installer()->run($sandbox->job());

    expect($result['composer_project'])->toBe($elsewhere);
    expect($sandbox->runner->calls[0]['cwd'])->toBe($elsewhere);
    expect(Files::readJson($elsewhere . '/composer.json')['require'])->toHaveKey('acme/widgets-api');
});
