<?php

use Fleetbase\RegistryBridge\Support\Installer\ExtensionsManifest;
use Fleetbase\RegistryBridge\Support\Installer\Files;
use Fleetbase\RegistryBridge\Support\Installer\InstallerJobStore;
use Fleetbase\RegistryBridge\Support\Installer\InstallerQueue;
use Fleetbase\RegistryBridge\Support\Installer\InstallLog;
use Fleetbase\RegistryBridge\Support\Installer\InstallProgress;
use Fleetbase\RegistryBridge\Tests\Installer\Fakes\InstallerSandbox;
use Fleetbase\RegistryBridge\Tests\Installer\Fakes\RecordingDispatcher;
use Fleetbase\RegistryBridge\Tests\Installer\Fakes\RecordingPublisher;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

/*
 * The user-facing progress contract (phases, one percentage, numbered events) and the speed-ups around
 * Composer and artisan.
 */

beforeEach(function () {
    $this->sandbox = new InstallerSandbox();
});

afterEach(function () {
    $this->sandbox->cleanup();
});

test('stages map onto a few plain phases per action', function () {
    expect(InstallProgress::phases('install'))->toBe(['prepare', 'download', 'install', 'finish'])
        ->and(InstallProgress::phases('update'))->toBe(['prepare', 'download', 'install', 'finish'])
        ->and(InstallProgress::phases('uninstall'))->toBe(['prepare', 'remove', 'finish']);

    $install = array_map(fn ($stage) => InstallProgress::phase('install', $stage), ['resolve', 'download', 'snapshot', 'composer', 'engine', 'build', 'caches', 'record']);
    expect($install)->toBe(['prepare', 'download', 'install', 'install', 'install', 'install', 'finish', 'finish']);

    $uninstall = array_map(fn ($stage) => InstallProgress::phase('uninstall', $stage), ['resolve', 'snapshot', 'composer', 'engine', 'build', 'caches', 'record']);
    expect($uninstall)->toBe(['prepare', 'prepare', 'remove', 'remove', 'remove', 'finish', 'finish']);
});

test('the overall percentage follows the stage weights and stays below 100 while running', function () {
    $plan = InstallProgress::DEFAULT_INSTALL_PLAN;

    expect(InstallProgress::percent($plan, [], 'resolve', 0))->toBe(0)
        ->and(InstallProgress::percent($plan, ['resolve', 'download', 'snapshot'], 'composer', 50))->toBe(40)
        ->and(InstallProgress::percent($plan, $plan, 'record', 100))->toBe(99);
});

test('install events carry phase, percent and a sequence number that never skips', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('1.0.0');

    $result = $sandbox->installer()->run($sandbox->job());

    expect($result['status'])->toBe('completed');
    $events = $sandbox->publisher->payloads();
    expect(array_column($events, 'seq'))->toBe(range(1, count($events)));
    expect(array_values(array_unique(array_column($events, 'phase'))))->toBe(['prepare', 'download', 'install', 'finish']);

    $percents = array_column($events, 'percent');
    $sorted   = $percents;
    sort($sorted);
    expect($percents)->toBe($sorted, 'the percentage never goes backwards');
    expect(max(array_slice($percents, 0, -1)))->toBeLessThan(100);
    expect(end($events))->toMatchArray(['v' => 2, 'status' => 'completed', 'phase' => 'finish', 'percent' => 100, 'action' => 'install']);

    expect(array_keys($result['timings']))->toBe(['resolve', 'download', 'snapshot', 'composer', 'engine', 'caches', 'record']);
    expect($result['duration'])->toBeFloat();
});

test('uninstall events use the remove phase', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('1.0.0');
    $sandbox->installer()->run($sandbox->job());
    $sandbox->publisher->events = [];

    $sandbox->installer()->run($sandbox->job('uninstall'));

    expect(array_values(array_unique(array_column($sandbox->publisher->payloads(), 'phase'))))->toBe(['prepare', 'remove', 'finish']);
});

test('a failure event names the phase it failed in', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('1.0.0');
    $sandbox->runner->on(['composer', 'require'], 2);

    $result = $sandbox->installer()->run($sandbox->job());

    expect($result['failed_phase'])->toBe('install');
    expect($sandbox->publisher->last())->toMatchArray(['status' => 'failed', 'phase' => 'install', 'stage' => 'composer']);
});

test('the job record is finished before the final event is published', function () {
    $sandbox = $this->sandbox;
    $sandbox->release('1.0.0');
    $locks = new ArrayStore();
    $store = new InstallerJobStore(new Repository($locks));
    $queue = new InstallerQueue($store, $locks, new RecordingDispatcher());

    $seen               = [];
    $sandbox->publisher = new class($store, $seen) extends RecordingPublisher {
        public function __construct(private InstallerJobStore $store, private array &$seen)
        {
        }

        public function publish(string $channel, array $payload): void
        {
            parent::publish($channel, $payload);
            if (in_array($payload['status'], ['completed', 'failed'], true)) {
                $this->seen[] = $this->store->get($payload['job']);
            }
        }
    };

    $job    = $queue->enqueue(['action' => 'install', 'extension' => $sandbox->extension->toArray(), 'version' => null, 'company' => 'company-1', 'user' => null]);
    $record = $queue->process($job['id'], $sandbox->installer(), fn () => null);

    expect($record['status'])->toBe('completed');
    expect($seen)->toHaveCount(1);
    expect($seen[0])->toMatchArray(['status' => 'completed', 'percent' => 100, 'phase' => 'finish']);
    expect($seen[0]['result']['timings'])->toHaveKey('composer');
});

test('the manifest keeps core packages off Packagist and composer runs without scripts', function () {
    $sandbox    = $this->sandbox;
    $extensions = $sandbox->withExtensionsManifest(['scripts' => ['post-autoload-dump' => ['@php ../artisan package:discover --ansi']]]);
    Files::write($extensions . '/core-platform/composer.json', (string) json_encode(['name' => 'fleetbase/core-platform', 'type' => 'metapackage', 'provide' => ['fleetbase/core-api' => '2.0.4', 'Laravel/Framework' => '12.0.0'], 'replace' => ['fleetbase/fleetops-api' => '0.7.0']]));
    $sandbox->release('1.0.0');

    $result = $sandbox->installer()->run($sandbox->job());

    expect($result['status'])->toBe('completed');
    expect(Files::readJson($extensions . '/composer.json')['repositories']['packagist.org'])->toBe([
        'type'    => 'composer',
        'url'     => 'https://repo.packagist.org',
        'exclude' => ['fleetbase/core-api', 'fleetbase/fleetops-api', 'laravel/framework'],
    ]);
    expect($sandbox->runner->commands())->toBe([
        'composer update acme/widgets-api fleetbase/core-platform --no-interaction --no-progress --no-audit --no-ansi --no-scripts',
        'php artisan registry:installer-refresh --discover --run=config:clear --run=route:clear --run=view:clear --run=event:clear --run=queue:restart',
    ]);
});

test('a manifest with other scripts, or with Packagist turned off, is left as it is', function () {
    $manifest = new ExtensionsManifest($this->sandbox->withExtensionsManifest(['scripts' => ['post-autoload-dump' => ['@php ../artisan package:discover --ansi', 'echo done']], 'repositories' => ['core-platform' => ['type' => 'path', 'url' => 'core-platform'], 'packagist.org' => false]]));
    Files::write($manifest->directory . '/core-platform/composer.json', (string) json_encode(['provide' => ['fleetbase/core-api' => '2.0.4']]));
    $before = file_get_contents($manifest->file());

    expect($manifest->scriptsOnlyDiscoverPackages())->toBeFalse()
        ->and($manifest->excludeCorePackagesFromPackagist())->toBe(0)
        ->and(file_get_contents($manifest->file()))->toBe($before);
});

test('excluding core packages is idempotent', function () {
    $manifest = new ExtensionsManifest($this->sandbox->withExtensionsManifest());
    Files::write($manifest->directory . '/core-platform/composer.json', (string) json_encode(['provide' => ['fleetbase/core-api' => '2.0.4']]));

    expect($manifest->excludeCorePackagesFromPackagist())->toBe(1);
    $once = file_get_contents($manifest->file());
    expect($manifest->excludeCorePackagesFromPackagist())->toBe(1)
        ->and(file_get_contents($manifest->file()))->toBe($once)
        ->and($manifest->scriptsOnlyDiscoverPackages())->toBeTrue('no scripts at all');
});

test('the job log drops colour codes', function () {
    $log = new InstallLog();
    $log->add("\e[32mfleetbase/core-api\e[39m \e[90m....\e[39m DONE");

    expect($log->all())->toBe(['fleetbase/core-api .... DONE']);
});
