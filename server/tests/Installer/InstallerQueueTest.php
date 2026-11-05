<?php

use Fleetbase\RegistryBridge\Jobs\InstallExtensionJob;
use Fleetbase\RegistryBridge\Support\Installer\InstallerException;
use Fleetbase\RegistryBridge\Support\Installer\InstallerJobStore;
use Fleetbase\RegistryBridge\Support\Installer\InstallerQueue;
use Fleetbase\RegistryBridge\Support\Installer\InstallerService;
use Fleetbase\RegistryBridge\Support\Installer\ProcessResult;
use Fleetbase\RegistryBridge\Tests\Installer\Fakes\InstallerSandbox;
use Fleetbase\RegistryBridge\Tests\Installer\Fakes\RecordingDispatcher;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

beforeEach(function () {
    $this->sandbox = new InstallerSandbox();
    $this->sandbox->release('1.0.0');
    $this->locks   = new ArrayStore();
    $this->store   = new InstallerJobStore(new Repository($this->locks));
    $this->bus     = new RecordingDispatcher();
    $this->queue   = new InstallerQueue($this->store, $this->locks, $this->bus);
    $this->service = fn (bool $preinstalled = false) => new InstallerService($this->queue, $this->store, $this->sandbox->releases, $this->sandbox->recorder, $this->sandbox->installer(), $preinstalled);
});

afterEach(function () {
    $this->sandbox->cleanup();
});

function attributes(string $company = 'company-1', string $uuid = 'ext-uuid-1'): array
{
    return ['action' => 'install', 'extension' => ['uuid' => $uuid, 'id' => 'extension_widgets', 'name' => 'Widgets'], 'version' => null, 'company' => $company, 'user' => null];
}

test('enqueue records the job and dispatches it on the installer queue', function () {
    $job = $this->queue->enqueue(attributes());

    expect($job)->toMatchArray(['status' => 'queued', 'position' => 0, 'action' => 'install', 'company' => 'company-1']);
    expect($job['id'])->toMatch('/^[0-9a-f-]{36}$/');
    expect($this->bus->dispatched)->toHaveCount(1);
    expect($this->bus->dispatched[0])->toBeInstanceOf(InstallExtensionJob::class);
    expect($this->bus->dispatched[0]->jobId)->toBe($job['id']);
    expect($this->bus->dispatched[0]->queue)->toBe('installer');
    expect($this->bus->dispatched[0]->timeout)->toBe(7200);
});

test('later requests report how many jobs are ahead of them', function () {
    $first  = $this->queue->enqueue(attributes('company-1', 'a'));
    $second = $this->queue->enqueue(attributes('company-1', 'b'));
    $third  = $this->queue->enqueue(attributes('company-2', 'c'));

    expect([$first['position'], $second['position'], $third['position']])->toBe([0, 1, 2]);

    $this->store->update($first['id'], ['status' => 'completed']);
    expect($this->store->find($third['id'])['position'])->toBe(1);
    expect($this->store->find($first['id'])['position'])->toBeNull();
});

test('a worker runs the job under the instance lock and stores the result', function () {
    $job = $this->queue->enqueue(attributes());

    $record = $this->queue->process($job['id'], $this->sandbox->installer(), fn () => throw new RuntimeException('should not release'));

    expect($record['status'])->toBe('completed');
    expect($record['progress'])->toBe(100);
    expect($record['result']['mode'])->toBe('runtime');
    expect($record['started_at'])->not->toBeNull();
    expect($record['finished_at'])->not->toBeNull();
    expect($this->store->queue())->toBe([]);
    expect($this->locks->lock(InstallerQueue::LOCK, 10)->get())->toBeTrue('the lock is released afterwards');
});

test('progress updates the job record while the job runs', function () {
    $job       = $this->queue->enqueue(attributes());
    $snapshots = [];
    $this->sandbox->runner->on(['php', 'artisan', 'queue:restart'], function () use ($job, &$snapshots) {
        $snapshots[] = $this->store->find($job['id']);

        return 0;
    });

    $this->queue->process($job['id'], $this->sandbox->installer(), null);

    expect($snapshots[0])->toMatchArray(['status' => 'running', 'stage' => 'caches', 'step' => 'cache.clear', 'position' => 0]);
    expect($snapshots[0]['heartbeat_at'])->toBeGreaterThan(time() - 5);
});

test('a second job waits while the lock is held and is released back to the queue', function () {
    $first  = $this->queue->enqueue(attributes('company-1', 'a'));
    $second = $this->queue->enqueue(attributes('company-1', 'b'));
    $this->locks->lock(InstallerQueue::LOCK, 100, $first['id'])->get();

    $released = null;
    $record   = $this->queue->process($second['id'], $this->sandbox->installer(), function (int $delay) use (&$released) {
        $released = $delay;
    });

    expect($released)->toBe(15);
    expect($record)->toMatchArray(['status' => 'queued', 'position' => 1]);
    expect($this->sandbox->runner->calls)->toBe([]);
});

test('sync mode refuses instead of waiting when another job holds the lock', function () {
    $this->locks->lock(InstallerQueue::LOCK, 100, 'someone-else')->get();

    expect(fn () => $this->queue->runNow(attributes(), $this->sandbox->installer()))->toThrow(InstallerException::class, 'Another installer job is running');
    expect($this->store->queue())->toBe([]);
});

test('a duplicate delivery of a running job is ignored', function () {
    $job = $this->queue->enqueue(attributes());
    $this->store->update($job['id'], ['status' => 'running', 'heartbeat_at' => time()]);

    $record = $this->queue->process($job['id'], $this->sandbox->installer(), fn () => throw new RuntimeException('should not release'));

    expect($record['status'])->toBe('running');
    expect($this->sandbox->runner->calls)->toBe([]);
});

test('finished jobs are not run again', function () {
    $job = $this->queue->enqueue(attributes());
    $this->store->update($job['id'], ['status' => 'completed']);

    expect($this->queue->process($job['id'], $this->sandbox->installer(), null)['status'])->toBe('completed');
    expect($this->sandbox->runner->calls)->toBe([]);
});

test('a job whose worker died is rolled back by the next attempt, even though its lock is still held', function () {
    $job = $this->queue->enqueue(attributes());
    $this->locks->lock(InstallerQueue::LOCK, 10800, $job['id'])->get();
    $this->store->update($job['id'], ['status' => 'running', 'stage' => 'composer', 'step' => 'server.install', 'heartbeat_at' => time() - 3600]);

    $record = $this->queue->process($job['id'], $this->sandbox->installer(), fn () => throw new RuntimeException('should not release'));

    expect($record['status'])->toBe('failed');
    expect($record['failed_stage'])->toBe('composer');
    expect($record['message'])->toContain('stopped before this job finished');
});

test('a job that times out is marked interrupted and dispatched again for rollback', function () {
    $job = $this->queue->enqueue(attributes());
    $this->store->update($job['id'], ['status' => 'running', 'heartbeat_at' => time()]);

    $this->queue->interrupt($job['id'], 'job timed out');

    expect($this->store->get($job['id']))->toMatchArray(['status' => 'interrupted']);
    expect($this->bus->dispatched)->toHaveCount(2);

    $record = $this->queue->process($job['id'], $this->sandbox->installer(), null);
    expect($record['status'])->toBe('failed');
    expect($record['message'])->toContain('job timed out');
});

test('a job that never got the lock fails when its retries run out', function () {
    $job = $this->queue->enqueue(attributes());

    $this->queue->interrupt($job['id'], 'retry window passed');

    expect($this->store->get($job['id']))->toMatchArray(['status' => 'failed']);
    expect($this->store->queue())->toBe([]);
});

test('a failed job keeps the failed stage, message and log tail on the record', function () {
    $this->sandbox->runner->on(['composer', 'require'], fn () => new ProcessResult(1, "Problem 1\n  - conflict"));
    $job = $this->queue->enqueue(attributes());

    $record = $this->queue->process($job['id'], $this->sandbox->installer(), null);

    expect($record['status'])->toBe('failed');
    expect($record['failed_stage'])->toBe('composer');
    expect($record['log'])->toContain('  - conflict');
    expect($record['result']['rollback']['performed'])->toBeTrue();
});

test('the service validates requests before queueing them', function () {
    $service = ($this->service)();

    expect(fn () => $service->request('install', 'nope'))->toThrow(InstallerException::class, 'was not found');
    expect(fn () => $service->request('uninstall', 'extension_widgets'))->toThrow(InstallerException::class, 'not installed');
    expect(fn () => $service->request('update', 'extension_widgets'))->toThrow(InstallerException::class, 'not installed');
    expect(fn () => $service->request('install', 'extension_widgets', '1.0; rm -rf /'))->toThrow(InstallerException::class, 'valid version constraint');
    expect(fn () => $service->request('install', 'extension_widgets', null, 'company-9'))->toThrow(InstallerException::class, 'Company "company-9" was not found');

    $this->sandbox->recorder->companies = ['company-1', 'company-2'];
    expect(fn () => $service->request('install', 'extension_widgets'))->toThrow(InstallerException::class, 'pass the company');

    $this->sandbox->recorder->installs['company-1']['ext-uuid-1'] = ['version' => '1.0.0'];
    expect(fn () => $service->request('install', 'extension_widgets', null, 'company-1'))->toThrow(InstallerException::class, 'already installed');
    expect($this->bus->dispatched)->toBe([]);
});

test('the service queues one job per company and extension', function () {
    $service = ($this->service)();

    $first  = $service->request('install', 'extension_widgets', '1.0.0', null, 'user-1');
    $repeat = $service->request('install', 'extension_widgets');

    expect($first)->toMatchArray(['status' => 'queued', 'version' => '1.0.0', 'company' => 'company-1', 'user' => 'user-1', 'position' => 0]);
    expect($repeat['id'])->toBe($first['id']);
    expect($repeat['duplicate'])->toBeTrue();
    expect($this->bus->dispatched)->toHaveCount(1);
});

test('the service runs a job in process when asked to', function () {
    $job = ($this->service)()->request('install', 'extension_widgets', null, null, null, true);

    expect($job['status'])->toBe('completed');
    expect($this->bus->dispatched)->toBe([]);
    expect($this->sandbox->recorder->installs['company-1']['ext-uuid-1']['version'])->toBe('1.0.0');
});

test('preinstalled instances only record the install', function () {
    $service = ($this->service)(true);

    $job = $service->request('install', 'extension_widgets');
    expect($job['status'])->toBe('completed');
    expect($job['result']['mode'])->toBe('preinstalled');
    expect($this->sandbox->recorder->installs['company-1']['ext-uuid-1'])->toMatchArray(['mode' => 'preinstalled', 'version' => '1.0.0']);

    $service->request('uninstall', 'extension_widgets');
    expect($this->sandbox->recorder->installs['company-1'])->toBe([]);
    expect($this->sandbox->runner->calls)->toBe([]);
    expect($this->bus->dispatched)->toBe([]);
});

test('jobs are only visible to their company', function () {
    $service = ($this->service)();
    $job     = $service->request('install', 'extension_widgets');

    expect($service->find($job['id'], 'company-1')['id'])->toBe($job['id']);
    expect($service->find($job['id'], 'company-2'))->toBeNull();
    expect($service->find('missing', 'company-1'))->toBeNull();
    expect($service->find($job['id'])['id'])->toBe($job['id']);
});
