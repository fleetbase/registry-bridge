<?php

/*
 * Per-install auto-update policies: registry:auto-update picks the newest compatible release inside each
 * install's policy from the service's `me/updates` (recorded) and queues it on the installer queue, the
 * same path as the console's Update button.
 */

use Fleetbase\RegistryBridge\Console\Commands\AutoUpdateExtensions;
use Fleetbase\RegistryBridge\Jobs\InstallExtensionJob;
use Fleetbase\RegistryBridge\Support\AutoUpdate\AutoUpdater;
use Fleetbase\RegistryBridge\Support\AutoUpdate\Contracts\AutoUpdateInstalls;
use Fleetbase\RegistryBridge\Support\AutoUpdate\UpdatePolicy;
use Fleetbase\RegistryBridge\Support\Installer\InstallerJobStore;
use Fleetbase\RegistryBridge\Support\Installer\InstallerQueue;
use Fleetbase\RegistryBridge\Support\Installer\InstallerService;
use Fleetbase\RegistryBridge\Tests\Installer\Fakes\InstallerSandbox;
use Fleetbase\RegistryBridge\Tests\Installer\Fakes\RecordingDispatcher;
use Fleetbase\RegistryBridge\Tests\Support\RegistryServiceFake;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher as Events;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Install records with policies, in memory.
 */
class MemoryAutoUpdateInstalls implements AutoUpdateInstalls
{
    /** @var array<int, array<string, mixed>> */
    public array $installs = [];

    public function add(string $policy, ?string $version = '1.0.0', string $extension = 'extension_widgets', string $company = 'company-1', ?array $last = null): self
    {
        $this->installs[] = ['company' => $company, 'extension_id' => $extension, 'name' => 'Widgets', 'version' => $version, 'policy' => $policy, 'last' => $last];

        return $this;
    }

    public function withPolicy(): array
    {
        return array_values(array_filter($this->installs, fn ($install) => $install['policy'] !== UpdatePolicy::NONE));
    }

    public function remember(string $company, string $extensionId, array $state): void
    {
        foreach ($this->installs as $i => $install) {
            if ($install['company'] === $company && $install['extension_id'] === $extensionId) {
                $this->installs[$i]['last'] = $state;
            }
        }
    }

    public function last(): ?array
    {
        return $this->installs[0]['last'] ?? null;
    }
}

/**
 * `me/updates` rows as recorded, the demo extension standing in for the sandbox's Widgets.
 *
 * @return array<int, array<string, mixed>>
 */
function policyUpdates(bool $withPolicyFields = true): array
{
    $rows                       = RegistryServiceFake::body('instances-me-updates-policies')['data'];
    $rows[0]['extension']['id'] = 'extension_widgets';
    if (!$withPolicyFields) {
        unset($rows[0]['latest_patch'], $rows[0]['latest_minor']);
    }

    return $rows;
}

beforeEach(function () {
    $this->sandbox = new InstallerSandbox();
    $this->sandbox->release('1.0.0');
    foreach (['1.0.3', '1.2.0', '2.0.0'] as $version) {
        $this->sandbox->release($version, current: false);
    }
    $this->sandbox->recorder->installs['company-1']['ext-uuid-1'] = ['version' => '1.0.0'];

    $locks          = new ArrayStore();
    $this->store    = new InstallerJobStore(new Repository($locks));
    $this->bus      = new RecordingDispatcher();
    $this->queue    = new InstallerQueue($this->store, $locks, $this->bus);
    $this->installs = new MemoryAutoUpdateInstalls();
    $this->rows     = policyUpdates();

    $this->updater = function (bool $preinstalled = false, bool $sync = false): AutoUpdater {
        $service = new InstallerService($this->queue, $this->store, $this->sandbox->releases, $this->sandbox->recorder, $this->sandbox->installer(), $preinstalled);

        return new AutoUpdater($service, $this->store, $this->installs, fn () => $this->rows, fn () => '2026-11-18T10:00:00+00:00', $sync);
    };
});

afterEach(function () {
    $this->sandbox->cleanup();
});

test('a patch policy queues the newest compatible patch release on the installer queue', function () {
    $this->installs->add(UpdatePolicy::PATCH);

    $results = ($this->updater)()->run();

    expect($results)->toHaveCount(1)
        ->and($results[0])->toMatchArray(['extension' => 'extension_widgets', 'policy' => 'patch', 'installed' => '1.0.0', 'target' => '1.0.3', 'result' => AutoUpdater::QUEUED]);
    expect($this->bus->dispatched)->toHaveCount(1)
        ->and($this->bus->dispatched[0])->toBeInstanceOf(InstallExtensionJob::class)
        ->and($this->bus->dispatched[0]->queue)->toBe('installer');
    expect($this->store->get($this->bus->dispatched[0]->jobId))->toMatchArray(['action' => 'update', 'version' => '1.0.3', 'company' => 'company-1', 'status' => 'queued']);
    expect($this->installs->last())->toBe(['version' => '1.0.3', 'job' => $results[0]['job'], 'status' => 'queued', 'message' => null, 'at' => '2026-11-18T10:00:00+00:00']);
});

test('a minor policy takes the newest compatible minor release, never the next major', function () {
    $this->installs->add(UpdatePolicy::MINOR);

    $results = ($this->updater)()->run();

    expect($results[0])->toMatchArray(['target' => '1.2.0', 'result' => AutoUpdater::QUEUED]);
    expect($this->store->get($this->bus->dispatched[0]->jobId)['version'])->toBe('1.2.0');
});

test('installs without a policy are left alone', function () {
    $this->installs->add(UpdatePolicy::NONE);

    expect(($this->updater)()->run())->toBe([])
        ->and($this->bus->dispatched)->toBe([]);
});

test('nothing is queued while the last automatic update is pending, or after it failed', function () {
    $this->installs->add(UpdatePolicy::PATCH);
    $first = ($this->updater)()->run()[0];

    $again = ($this->updater)()->run()[0];
    expect($again)->toMatchArray(['result' => AutoUpdater::PENDING, 'target' => '1.0.3', 'job' => $first['job']])
        ->and($this->bus->dispatched)->toHaveCount(1);

    // The job failed (and rolled back): the same version is not retried on its own.
    $this->store->update($first['job'], ['status' => 'failed', 'message' => 'composer require failed']);
    expect(($this->updater)()->run()[0])->toMatchArray(['result' => AutoUpdater::SKIPPED_FAILED, 'target' => '1.0.3'])
        ->and($this->bus->dispatched)->toHaveCount(1);

    // Asked to retry, it queues again.
    expect(($this->updater)()->run(retryFailed: true)[0])->toMatchArray(['result' => AutoUpdater::QUEUED, 'target' => '1.0.3'])
        ->and($this->bus->dispatched)->toHaveCount(2);
});

test('an attempt whose job record expired before the install moved is treated as failed', function () {
    $this->installs->add(UpdatePolicy::PATCH, last: ['version' => '1.0.3', 'job' => 'job-gone', 'status' => 'queued', 'message' => null, 'at' => '2026-11-01T00:00:00+00:00']);

    expect(($this->updater)()->run()[0])->toMatchArray(['result' => AutoUpdater::SKIPPED_FAILED, 'job' => 'job-gone'])
        ->and($this->bus->dispatched)->toBe([]);
});

test('a newer release than the one that failed is tried', function () {
    $this->installs->add(UpdatePolicy::MINOR, last: ['version' => '1.0.3', 'job' => null, 'status' => 'failed', 'message' => 'x', 'at' => '2026-11-01T00:00:00+00:00']);

    expect(($this->updater)()->run()[0])->toMatchArray(['result' => AutoUpdater::QUEUED, 'target' => '1.2.0']);
});

test('a dry run reports what would be queued', function () {
    $this->installs->add(UpdatePolicy::MINOR);

    expect(($this->updater)()->run(dryRun: true)[0])->toMatchArray(['result' => AutoUpdater::WOULD_UPDATE, 'target' => '1.2.0'])
        ->and($this->bus->dispatched)->toBe([])
        ->and($this->installs->last())->toBeNull();
});

test('against a service without latest_patch and latest_minor, only latest inside the policy is taken', function () {
    $this->rows = policyUpdates(withPolicyFields: false);
    $this->installs->add(UpdatePolicy::MINOR);
    expect(($this->updater)()->run(dryRun: true)[0])->toMatchArray(['result' => AutoUpdater::WOULD_UPDATE, 'target' => '1.2.0']);

    $this->installs->installs = [];
    $this->installs->add(UpdatePolicy::PATCH);
    expect(($this->updater)()->run(dryRun: true)[0])->toMatchArray(['result' => AutoUpdater::UP_TO_DATE, 'target' => null]);
});

test('up to date and unreported installs queue nothing', function () {
    $this->installs->add(UpdatePolicy::MINOR, '1.2.0');
    $this->installs->add(UpdatePolicy::MINOR, '0.4.1', 'extension_unknown');

    $results = ($this->updater)()->run();

    expect(array_column($results, 'result'))->toBe([AutoUpdater::UP_TO_DATE, AutoUpdater::NOT_REPORTED])
        ->and($this->bus->dispatched)->toBe([]);
});

test('a refused update is reported and remembered', function () {
    unset($this->sandbox->recorder->installs['company-1']['ext-uuid-1']);
    $this->installs->add(UpdatePolicy::PATCH);

    expect(($this->updater)()->run()[0])->toMatchArray(['result' => AutoUpdater::ERROR, 'message' => 'This extension is not installed.'])
        ->and($this->installs->last())->toMatchArray(['version' => '1.0.3', 'status' => 'failed']);
});

test('with preinstalled extensions (cloud) only the install record changes', function () {
    $this->installs->add(UpdatePolicy::MINOR);

    $results = ($this->updater)(preinstalled: true)->run();

    expect($results[0])->toMatchArray(['result' => AutoUpdater::COMPLETED, 'target' => '1.2.0'])
        ->and($this->bus->dispatched)->toBe([])
        ->and($this->sandbox->runner->calls)->toBe([])
        ->and($this->sandbox->recorder->installs['company-1']['ext-uuid-1'])->toMatchArray(['version' => '1.2.0', 'mode' => 'preinstalled'])
        ->and($this->installs->last())->toMatchArray(['version' => '1.2.0', 'status' => 'completed']);
});

test('without a queue worker (installer.async off) the update runs in this process, with its snapshot', function () {
    $this->installs->add(UpdatePolicy::PATCH);

    $results = ($this->updater)(sync: true)->run();

    expect($results[0])->toMatchArray(['result' => AutoUpdater::COMPLETED, 'target' => '1.0.3'])
        ->and($this->bus->dispatched)->toBe([])
        ->and(array_filter($this->sandbox->runner->commands(), fn ($command) => str_starts_with($command, 'composer require')))->not->toBeEmpty()
        ->and($this->sandbox->recorder->installs['company-1']['ext-uuid-1']['version'] ?? null)->toBe('1.0.3')
        ->and($this->installs->last())->toMatchArray(['version' => '1.0.3', 'status' => 'completed']);
});

test('registry:auto-update prints a table and fails when an update was refused', function () {
    $container = new class() extends Container {
        public function runningUnitTests(): bool
        {
            return true;
        }
    };
    $container->instance(AutoUpdater::class, ($this->updater)());
    $run = function (array $input) use ($container): array {
        $app = new Artisan($container, new Events($container), 'test');
        $app->setAutoExit(false);
        $command = new AutoUpdateExtensions();
        $command->setLaravel($container);
        $app->add($command);
        $output = new BufferedOutput();

        return [$app->run(new ArrayInput(['command' => 'registry:auto-update', ...$input]), $output), $output->fetch()];
    };

    [$code, $output] = $run([]);
    expect($code)->toBe(0)->and($output)->toContain('No installed extension has an auto-update policy.');

    $this->installs->add(UpdatePolicy::MINOR);
    [$code, $output] = $run(['--dry-run' => true]);
    expect($code)->toBe(0)->and($output)->toContain('Widgets')->toContain('would-update')->toContain('1.2.0');

    [$code, $output] = $run(['--json' => true]);
    expect($code)->toBe(0)->and(json_decode($output, true)[0])->toMatchArray(['result' => 'queued', 'target' => '1.2.0']);

    unset($this->sandbox->recorder->installs['company-1']['ext-uuid-1']);
    $this->installs->installs = [];
    $this->installs->add(UpdatePolicy::PATCH);
    [$code, $output] = $run([]);
    expect($code)->toBe(1)->and($output)->toContain('This extension is not installed.');
});

test('policies follow caret and tilde rules', function (string $policy, string $installed, string $candidate, bool $allowed) {
    expect(UpdatePolicy::allows($policy, $installed, $candidate))->toBe($allowed);
})->with([
    'patch: patch bump'               => ['patch', '1.2.3', '1.2.9', true],
    'patch: minor bump'               => ['patch', '1.2.3', '1.3.0', false],
    'minor: minor bump'               => ['minor', '1.2.3', '1.9.0', true],
    'minor: major bump'               => ['minor', '1.2.3', '2.0.0', false],
    'minor: 0.x minor bump may break' => ['minor', '0.3.1', '0.4.0', false],
    'minor: 0.x patch bump'           => ['minor', '0.3.1', '0.3.4', true],
    'not newer'                       => ['minor', '1.2.3', '1.2.3', false],
    'older'                           => ['patch', '1.2.3', '1.2.0', false],
    'prerelease of a later version'   => ['patch', '1.2.3', '1.2.4-beta.1', false],
    'prerelease to its release'       => ['patch', '1.2.3-beta.1', '1.2.3', true],
    'numeric prerelease order'        => ['patch', '1.2.3-beta.2', '1.2.3-beta.10', true],
    'none'                            => ['none', '1.2.3', '1.2.4', false],
    'unparseable installed'           => ['minor', 'dev-main', '1.2.4', false],
]);

test('installed listings carry their policy and last attempt', function () {
    $decorated = UpdatePolicy::decorate([['id' => 'ext_a', 'name' => 'A'], ['id' => 'ext_b', 'name' => 'B']], [
        'ext_a' => ['policy' => 'minor', 'last' => ['version' => '1.2.0', 'status' => 'queued']],
        'ext_c' => ['policy' => 'patch'],
    ]);

    expect($decorated)->toBe([
        ['id' => 'ext_a', 'name' => 'A', 'auto_update' => 'minor', 'auto_update_last' => ['version' => '1.2.0', 'status' => 'queued']],
        ['id' => 'ext_b', 'name' => 'B', 'auto_update' => 'none', 'auto_update_last' => null],
    ]);
    expect(UpdatePolicy::normalize('major'))->toBe('none');
});
