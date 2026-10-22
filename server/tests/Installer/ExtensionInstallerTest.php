<?php

use Fleetbase\RegistryBridge\Support\Installer\Files;
use Fleetbase\RegistryBridge\Support\Installer\NodeEngineCompat;
use Fleetbase\RegistryBridge\Support\Installer\ProcessResult;
use Fleetbase\RegistryBridge\Support\Installer\Snapshot;
use Fleetbase\RegistryBridge\Tests\Installer\Fakes\InstallerSandbox;

beforeEach(function () {
    $this->sandbox   = new InstallerSandbox();
    $this->engineDir = $this->sandbox->runtime . '/' . InstallerSandbox::ENGINE;
});

afterEach(function () {
    $this->sandbox->cleanup();
});

/**
 * The contents of the files an install snapshots, to compare before and after a rollback.
 */
function sandboxState(InstallerSandbox $sandbox): array
{
    $state = [];
    foreach ([$sandbox->api . '/composer.json', $sandbox->api . '/composer.lock', $sandbox->console . '/package.json', $sandbox->console . '/pnpm-lock.yaml', $sandbox->runtime . '/runtime-engines.json'] as $file) {
        $state[$file] = is_file($file) ? file_get_contents($file) : null;
    }

    return $state;
}

test('a release with a prebuilt dist installs as a runtime engine without a console build', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('1.0.0');

    $result = $sandbox->installer()->run($sandbox->job());

    expect($result['status'])->toBe('completed');
    expect($result['mode'])->toBe('runtime');
    expect($result['stages'])->toBe(['resolve', 'download', 'snapshot', 'composer', 'engine', 'caches', 'record']);
    expect($sandbox->runner->commands())->toBe([
        'composer require acme/widgets-api:1.0.0 --no-interaction',
        'php artisan optimize:clear',
        'php artisan queue:restart',
    ]);
    expect($sandbox->runner->calls[0]['cwd'])->toBe($sandbox->api);

    // Unpacked from the archive's package/ folder into engines-dist/<name>/, the path the console serves.
    expect(file_get_contents($this->engineDir . '/assets/engine.js'))->toContain('v1.0.0');
    expect(Files::listFiles($this->engineDir))->toBe(['assets/engine.css', 'assets/engine.js', 'extension.js', 'host-app.js', 'manifest.json', 'routes.js', 'translations.json']);

    expect($sandbox->manifest()->toResponse())->toBe(['engines' => [[
        'name'         => InstallerSandbox::ENGINE,
        'version'      => '1.0.0',
        'baseUrl'      => '/engines-dist/' . InstallerSandbox::ENGINE . '/',
        'mount'        => ['as' => 'widgets', 'path' => 'widgets', 'root' => false],
        'bundle'       => ['assets' => [['uri' => 'assets/engine.js', 'type' => 'js'], ['uri' => 'assets/engine.css', 'type' => 'css']], 'dependencies' => []],
        'config'       => ['modulePrefix' => InstallerSandbox::ENGINE, 'environment' => 'production'],
        'routes'       => 'routes.js',
        'hostApp'      => 'host-app.js',
        'translations' => 'translations.json',
        'extension'    => 'extension.js',
        'manifest'     => 'manifest.json',
        'requires'     => ['@fleetbase/ember-ui/components/button', '@fleetbase/ember-core/services/fetch'],
    ]]]);

    expect($sandbox->recorder->installs['company-1']['ext-uuid-1'])->toMatchArray(['version' => '1.0.0', 'mode' => 'runtime', 'job' => 'job-install']);
    expect($result['compat']['compatible'])->toBeTrue();
    expect(is_dir($sandbox->root . '/work/job-install'))->toBeFalse('the work directory is cleaned up');
});

test('every stage publishes step and progress on install.{company}.{extension}', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('1.0.0');

    $sandbox->installer()->run($sandbox->job());

    expect(array_unique(array_column($sandbox->publisher->events, 'channel')))->toBe(['install.company-1.ext-uuid-1']);
    expect($sandbox->publisher->steps())->toBe(['release.resolve', 'release.download', 'snapshot.create', 'server.install', 'engine.install', 'cache.clear', 'install.record', 'install.complete']);

    foreach ($sandbox->publisher->payloads() as $payload) {
        expect($payload)->toHaveKeys(['process', 'step', 'progress', 'job', 'status']);
        expect($payload['progress'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100);
        expect($payload['job'])->toBe('job-install');
    }

    // Composer output drives intermediate progress for the step the console already labels.
    $composer = array_column(array_filter($sandbox->publisher->payloads(), fn ($p) => $p['step'] === 'server.install'), 'progress');
    expect($composer)->toBe([0, 10, 20, 30, 50, 90, 100]);
    expect($sandbox->publisher->last())->toMatchArray(['step' => 'install.complete', 'status' => 'completed', 'progress' => 100]);
});

test('without a prebuilt dist the engine is installed with pnpm add and pnpm build', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('2.1.0', dist: false);

    $result = $sandbox->installer()->run($sandbox->job());

    expect($result['status'])->toBe('completed');
    expect($result['mode'])->toBe('build');
    expect($result['stages'])->toBe(['resolve', 'download', 'snapshot', 'composer', 'engine', 'build', 'caches', 'record']);
    expect($sandbox->runner->commands())->toBe([
        'composer require acme/widgets-api:2.1.0 --no-interaction',
        'pnpm add @acme/widgets-engine@2.1.0',
        'pnpm build --environment production',
        'php artisan optimize:clear',
        'php artisan queue:restart',
    ]);
    expect($sandbox->runner->calls[1]['cwd'])->toBe($sandbox->console);
    expect($sandbox->runner->calls[2]['cwd'])->toBe($sandbox->console);
    expect($sandbox->publisher->steps())->toContain('console.build');
    expect(array_filter($sandbox->publisher->payloads(), fn ($p) => $p['step'] === 'console.build')[array_key_first(array_filter($sandbox->publisher->payloads(), fn ($p) => $p['step'] === 'console.build'))]['process'])->toBe('build');
    expect($sandbox->manifest()->all())->toBe([]);
});

test('a console build keeps the runtime engines already unpacked in dist', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('1.0.0');
    $sandbox->installer()->run($sandbox->job());

    // A second extension without a prebuilt dist triggers a rebuild that replaces dist/.
    $other = new Fleetbase\RegistryBridge\Support\Installer\ExtensionRef('ext-uuid-2', 'extension_other', 'Other');
    $sandbox->releases->add(new Fleetbase\RegistryBridge\Support\Installer\Release($other, '3.0.0', null, null, '@acme/other-engine', '3.0.0'));

    $result = $sandbox->installer()->run(['id' => 'job-2', 'action' => 'install', 'extension' => $other->toArray(), 'company' => 'company-1']);

    expect($result['status'])->toBe('completed');
    expect(file_get_contents($sandbox->console . '/dist/index.html'))->toContain('rebuilt');
    expect(is_file($this->engineDir . '/assets/engine.js'))->toBeTrue();
    expect(array_column($sandbox->manifest()->all(), 'name'))->toBe([InstallerSandbox::ENGINE]);
});

test('a composer failure rolls back the snapshot and reports the stage and the last 50 log lines', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('1.0.0');
    $before = sandboxState($sandbox);

    $sandbox->runner->on(['composer', 'require'], function () use ($sandbox) {
        // Composer has half-written its files when it fails.
        file_put_contents($sandbox->api . '/composer.json', '{"broken": true}');
        $lines = array_map(fn ($i) => "resolving line {$i}", range(1, 80));

        return new ProcessResult(2, implode("\n", [...$lines, 'Problem 1', '  - acme/widgets-api requires php ^9']));
    });

    $result = $sandbox->installer()->run($sandbox->job());

    expect($result['status'])->toBe('failed');
    expect($result['failed_stage'])->toBe('composer');
    expect($result['step'])->toBe('server.install');
    expect($result['message'])->toContain('exited with code 2')->toContain('dependency compatibility');
    expect($result['log'])->toHaveCount(50);
    expect(end($result['log']))->toStartWith('!! composer failed');
    expect($result['log'])->toContain('  - acme/widgets-api requires php ^9');

    expect(sandboxState($sandbox))->toBe($before);
    expect($result['rollback']['performed'])->toBeTrue();
    expect($sandbox->runner->ran('composer install --no-interaction'))->toBeTrue('vendor/ is synced with the restored lock');
    expect($sandbox->recorder->installs)->toBe([]);

    $failure = $sandbox->publisher->last();
    expect($failure)->toMatchArray(['status' => 'failed', 'stage' => 'composer', 'step' => 'server.install', 'job' => 'job-install']);
    expect($sandbox->publisher->steps())->toContain('rollback');
    expect($failure['log'])->toBe($result['log']);
    expect($failure['message'])->toBe($result['message']);
});

test('an incompatible prebuilt engine is refused, composer is rolled back and nothing is unpacked', function () {
    $sandbox = $this->sandbox;
    $sandbox->writeHostModules('0.5.1');
    $sandbox->release('1.0.0');
    $before = sandboxState($sandbox);

    $result = $sandbox->installer()->run($sandbox->job());

    expect($result['status'])->toBe('failed');
    expect($result['failed_stage'])->toBe('engine');
    expect($result['message'])->toContain('not compatible')->toContain('@fleetbase/ember-ui 0.4.6 (built against) is not compatible with 0.5.1 (console)');
    expect($result['compat']['compatible'])->toBeFalse();
    expect(is_dir($this->engineDir))->toBeFalse();
    expect(sandboxState($sandbox))->toBe($before);
    expect($sandbox->runner->commands())->toBe([
        'composer require acme/widgets-api:1.0.0 --no-interaction',
        'composer install --no-interaction',
        'php artisan optimize:clear',
        'php artisan queue:restart',
    ]);
});

test('missing host modules are reported by name', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('1.0.0', dist: $sandbox->buildDist('1.0.0', requires: ['@fleetbase/ember-ui/components/button', '@fleetbase/ember-ui/components/new-widget']));

    $result = $sandbox->installer()->run($sandbox->job());

    expect($result['failed_stage'])->toBe('engine');
    expect($result['message'])->toContain('1 module(s)')->toContain('@fleetbase/ember-ui/components/new-widget');
});

test('the engine step runs the ember-core compat CLI against the console host-modules.json', function () {
    $sandbox         = $this->sandbox;
    $sandbox->compat = new NodeEngineCompat($sandbox->runner, '/console/node_modules/@fleetbase/ember-core/bin/check-engine-compat.js', $sandbox->console);
    $sandbox->runner->on(['node'], fn () => new ProcessResult(1, json_encode(['name' => InstallerSandbox::ENGINE, 'compatible' => false, 'missing' => ['x/y'], 'versions' => [], 'reasons' => ['the console does not define 1 module(s) the engine needs']])));
    $sandbox->release('1.0.0');

    $result = $sandbox->installer()->run($sandbox->job());

    $node = collect($sandbox->runner->calls)->first(fn ($call) => $call['command'][0] === 'node');
    expect($node['command'][1])->toBe('/console/node_modules/@fleetbase/ember-core/bin/check-engine-compat.js');
    expect(array_slice($node['command'], 2, 2))->toBe(['--manifest', $sandbox->root . '/work/job-install/dist/package/manifest.json']);
    expect(array_slice($node['command'], 4))->toBe(['--host', $sandbox->console . '/dist/host-modules.json', '--policy', 'minor', '--json']);
    expect($result['failed_stage'])->toBe('engine');
    expect($result['message'])->toContain('missing: x/y');
});

test('a console build without host-modules.json cannot take prebuilt engines', function () {
    $sandbox = $this->sandbox;
    unlink($sandbox->console . '/dist/host-modules.json');
    $sandbox->release('1.0.0');

    $result = $sandbox->installer()->run($sandbox->job());

    expect($result['failed_stage'])->toBe('engine');
    expect($result['message'])->toContain('no host-modules.json');
});

test('a failure after the engine is unpacked removes the dist and restores the runtime manifest', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('1.0.0');
    $before = sandboxState($sandbox);
    $sandbox->runner->on(['php', 'artisan', 'optimize:clear'], fn () => new ProcessResult(1, 'cache store unreachable'));

    $result = $sandbox->installer()->run($sandbox->job());

    expect($result['status'])->toBe('failed');
    expect($result['failed_stage'])->toBe('caches');
    expect(is_dir($this->engineDir))->toBeFalse();
    expect(is_file($sandbox->runtime . '/runtime-engines.json'))->toBeFalse();
    expect(sandboxState($sandbox))->toBe($before);
    expect($result['rollback']['restored'])->toContain($this->engineDir);
    expect($result['rollback']['errors'])->toHaveCount(1, 'the cache command fails again during rollback and is reported, not thrown');
});

test('a failure while recording the install rolls everything back', function () {
    $sandbox                         = $this->sandbox;
    $sandbox->recorder->failOnRecord = true;
    $sandbox->release('1.0.0');
    $before = sandboxState($sandbox);

    $result = $sandbox->installer()->run($sandbox->job());

    expect($result['failed_stage'])->toBe('record');
    expect($result['message'])->toBe('database is down');
    expect(sandboxState($sandbox))->toBe($before);
    expect(is_dir($this->engineDir))->toBeFalse();
});

test('a checksum mismatch fails the download before anything changes', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('1.0.0', checksum: 'sha256-' . str_repeat('0', 64));
    $before = sandboxState($sandbox);

    $result = $sandbox->installer()->run($sandbox->job());

    expect($result['failed_stage'])->toBe('download');
    expect($result['message'])->toContain('Checksum mismatch for the dist artifact');
    expect($result['rollback']['performed'])->toBeFalse();
    expect($sandbox->runner->calls)->toBe([]);
    expect(sandboxState($sandbox))->toBe($before);
});

test('an update replaces the engines-dist folder and restores the previous one when it fails', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('1.0.0');
    expect($sandbox->installer()->run($sandbox->job())['status'])->toBe('completed');
    $previous = sandboxState($sandbox);

    // 1.1.0 installs, then a later stage fails.
    $sandbox->release('1.1.0');
    $sandbox->runner->on(['php', 'artisan', 'queue:restart'], 1);
    $failed = $sandbox->installer()->run($sandbox->job('update', ['version' => '1.1.0']));

    expect($failed['status'])->toBe('failed');
    expect($failed['previous_version'])->toBe('1.0.0');
    expect(file_get_contents($this->engineDir . '/assets/engine.js'))->toContain('v1.0.0');
    expect($sandbox->manifest()->get(InstallerSandbox::ENGINE)['version'])->toBe('1.0.0');
    expect(sandboxState($sandbox))->toBe($previous);

    // Without the failure the update lands.
    $sandbox->runner->on(['php', 'artisan', 'queue:restart'], 0);
    $updated = $sandbox->installer()->run($sandbox->job('update', ['id' => 'job-update-2', 'version' => '1.1.0']));
    expect($updated['status'])->toBe('completed');
    expect($updated['previous_version'])->toBe('1.0.0');
    expect(file_get_contents($this->engineDir . '/assets/engine.js'))->toContain('v1.1.0');
    expect($sandbox->runner->commands())->toContain('composer require acme/widgets-api:1.1.0 --no-interaction');
    expect($sandbox->recorder->installs['company-1']['ext-uuid-1']['version'])->toBe('1.1.0');
});

test('uninstall removes a runtime engine without a build and publishes on uninstall.{company}.{extension}', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('1.0.0');
    $sandbox->installer()->run($sandbox->job());
    $sandbox->runner->calls     = [];
    $sandbox->publisher->events = [];

    $result = $sandbox->installer()->run($sandbox->job('uninstall'));

    expect($result['status'])->toBe('completed');
    expect($result['stages'])->toBe(['resolve', 'snapshot', 'composer', 'engine', 'caches', 'record']);
    expect($sandbox->runner->commands())->toBe(['composer remove acme/widgets-api --no-interaction', 'php artisan optimize:clear', 'php artisan queue:restart']);
    expect(is_dir($this->engineDir))->toBeFalse();
    expect($sandbox->manifest()->all())->toBe([]);
    expect($sandbox->recorder->installs['company-1'])->toBe([]);
    expect(array_unique(array_column($sandbox->publisher->events, 'channel')))->toBe(['uninstall.company-1.ext-uuid-1']);
    expect($sandbox->publisher->steps())->toBe(['release.resolve', 'snapshot.create', 'server.uninstall', 'engine.uninstall', 'cache.clear', 'install.record', 'uninstall.complete']);
});

test('uninstall of a built engine runs pnpm remove and a console build', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('2.0.0', dist: false);
    $sandbox->installer()->run($sandbox->job());
    $sandbox->runner->calls = [];

    $result = $sandbox->installer()->run($sandbox->job('uninstall'));

    expect($result['mode'])->toBe('build');
    expect($sandbox->runner->commands())->toBe([
        'composer remove acme/widgets-api --no-interaction',
        'pnpm remove @acme/widgets-engine',
        'pnpm build --environment production',
        'php artisan optimize:clear',
        'php artisan queue:restart',
    ]);
});

test('uninstall keeps the packages while another company has the extension', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('1.0.0');
    $sandbox->installer()->run($sandbox->job());
    $sandbox->recorder->installs['company-2']['ext-uuid-1'] = ['version' => '1.0.0'];
    $sandbox->runner->calls                                 = [];

    $result = $sandbox->installer()->run($sandbox->job('uninstall'));

    expect($result['status'])->toBe('completed');
    expect($result['packages_kept'])->toBeTrue();
    expect($result['stages'])->toBe(['resolve', 'record']);
    expect($sandbox->runner->calls)->toBe([]);
    expect(is_dir($this->engineDir))->toBeTrue();
    expect($sandbox->recorder->installs['company-1'])->toBe([]);
});

test('a job interrupted mid-install is rolled back from the snapshot on disk', function () {
    $sandbox = $this->sandbox;
    $before  = sandboxState($sandbox);

    // What a worker leaves behind when it dies after composer require.
    $snapshot = Snapshot::capture($sandbox->root . '/work/job-install/snapshot', array_keys($before));
    $snapshot->flag('composer');
    file_put_contents($sandbox->api . '/composer.json', '{"require":{"acme/widgets-api":"1.0.0"}}');

    $result = $sandbox->installer()->recover($sandbox->job() + ['stage' => 'composer', 'step' => 'server.install']);

    expect($result['status'])->toBe('failed');
    expect($result['failed_stage'])->toBe('composer');
    expect($result['message'])->toContain('stopped before this job finished');
    expect(sandboxState($sandbox))->toBe($before);
    expect($sandbox->runner->ran('composer install --no-interaction'))->toBeTrue();
    expect(is_dir($sandbox->root . '/work/job-install'))->toBeFalse();
});

test('releases with unsafe package names are refused at resolve', function () {
    $sandbox = $this->sandbox;
    $sandbox->releases->add(new Fleetbase\RegistryBridge\Support\Installer\Release($sandbox->extension, '1.0.0', 'acme/api', '1.0.0; rm -rf /', '../../etc', '1.0.0'));

    $result = $sandbox->installer()->run($sandbox->job());

    expect($result['failed_stage'])->toBe('resolve');
    expect($result['message'])->toContain('not a valid version constraint');
    expect($sandbox->runner->calls)->toBe([]);
});

test('a socket outage does not fail the install', function () {
    $sandbox            = $this->sandbox;
    $sandbox->publisher = new class() extends Fleetbase\RegistryBridge\Tests\Installer\Fakes\RecordingPublisher {
        public function publish(string $channel, array $payload): void
        {
            throw new RuntimeException('socket down');
        }
    };
    $sandbox->release('1.0.0');

    expect($sandbox->installer()->run($sandbox->job())['status'])->toBe('completed');
});
