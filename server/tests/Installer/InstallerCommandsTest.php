<?php

use Fleetbase\RegistryBridge\Console\Commands\InstallerJobStatus;
use Fleetbase\RegistryBridge\Console\Commands\InstallExtension;
use Fleetbase\RegistryBridge\Console\Commands\UninstallExtension;
use Fleetbase\RegistryBridge\Console\Commands\UpdateExtension;
use Fleetbase\RegistryBridge\Jobs\InstallExtensionJob;
use Fleetbase\RegistryBridge\Support\Installer\InstallerJobStore;
use Fleetbase\RegistryBridge\Support\Installer\InstallerQueue;
use Fleetbase\RegistryBridge\Support\Installer\InstallerService;
use Fleetbase\RegistryBridge\Tests\Installer\Fakes\InstallerSandbox;
use Fleetbase\RegistryBridge\Tests\Installer\Fakes\RecordingDispatcher;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher as Events;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    $this->sandbox = new InstallerSandbox();
    $this->sandbox->release('1.0.0');
    $this->sandbox->release('1.2.0', current: false);

    $locks       = new ArrayStore();
    $this->store = new InstallerJobStore(new Repository($locks));
    $this->bus   = new RecordingDispatcher();
    $queue       = new InstallerQueue($this->store, $locks, $this->bus);

    // Commands ask the application whether they run under tests (prompts setup).
    $this->container = new class() extends Container {
        public function runningUnitTests(): bool
        {
            return true;
        }
    };
    $this->container->instance(InstallerJobStore::class, $this->store);
    $this->container->instance(InstallerService::class, new InstallerService($queue, $this->store, $this->sandbox->releases, $this->sandbox->recorder, $this->sandbox->installer()));

    $this->artisan = function (array $input): array {
        $app = new Artisan($this->container, new Events($this->container), 'test');
        $app->setAutoExit(false);
        foreach ([InstallExtension::class, UninstallExtension::class, UpdateExtension::class, InstallerJobStatus::class] as $class) {
            $command = new $class();
            $command->setLaravel($this->container);
            $app->add($command);
        }
        $output = new BufferedOutput();
        $code   = $app->run(new ArrayInput($input), $output);

        return [$code, $output->fetch()];
    };
});

afterEach(function () {
    $this->sandbox->cleanup();
});

test('registry:install queues InstallExtensionJob on the installer queue', function () {
    [$code, $output] = ($this->artisan)(['command' => 'registry:install', 'extension' => 'extension_widgets']);

    expect($code)->toBe(0);
    expect($output)->toContain('Install of Widgets queued as job')->toContain('It is next in the installer queue.');
    expect($this->bus->dispatched)->toHaveCount(1);
    expect($this->bus->dispatched[0])->toBeInstanceOf(InstallExtensionJob::class);
    expect($this->bus->dispatched[0]->queue)->toBe('installer');
    expect($this->store->get($this->bus->dispatched[0]->jobId))->toMatchArray(['action' => 'install', 'version' => null, 'company' => 'company-1']);
});

test('registry:install --json prints the job record with its queue position', function () {
    $this->sandbox->releases->add(new Fleetbase\RegistryBridge\Support\Installer\Release(new Fleetbase\RegistryBridge\Support\Installer\ExtensionRef('ext-uuid-0', 'extension_zero', 'Zero'), '1.0.0', 'acme/zero', '1.0.0'));
    ($this->artisan)(['command' => 'registry:install', 'extension' => 'extension_zero']);

    [$code, $output] = ($this->artisan)(['command' => 'registry:install', 'extension' => 'extension_widgets', '--release' => '1.2.0', '--json' => true]);

    $job = json_decode($output, true);
    expect($code)->toBe(0);
    expect($job)->toMatchArray(['action' => 'install', 'status' => 'queued', 'version' => '1.2.0', 'position' => 1]);
    expect($job['extension'])->toBe(['uuid' => 'ext-uuid-1', 'id' => 'extension_widgets', 'name' => 'Widgets', 'source' => 'local']);
});

test('the version can follow the extension after an @', function () {
    ($this->artisan)(['command' => 'registry:install', 'extension' => 'extension_widgets@1.2.0']);

    expect($this->store->get($this->bus->dispatched[0]->jobId)['version'])->toBe('1.2.0');
});

test('registry:install --sync runs the job in process', function () {
    [$code, $output] = ($this->artisan)(['command' => 'registry:install', 'extension' => 'extension_widgets@1.2.0', '--sync' => true]);

    expect($code)->toBe(0);
    expect($output)->toContain('Install of Widgets completed');
    expect($this->bus->dispatched)->toBe([]);
    expect($this->sandbox->runner->commands())->toContain('composer require acme/widgets-api:1.2.0 --no-interaction');
});

test('a failed sync install exits non-zero with the stage and log tail', function () {
    $this->sandbox->runner->on(['composer', 'require'], fn () => new Fleetbase\RegistryBridge\Support\Installer\ProcessResult(2, 'Problem 1'));

    [$code, $output] = ($this->artisan)(['command' => 'registry:install', 'extension' => 'extension_widgets', '--sync' => true]);

    expect($code)->toBe(1);
    expect($output)->toContain('failed at stage "composer"')->toContain('Problem 1');
});

test('refused requests exit non-zero with the reason, as JSON when asked', function () {
    [$code, $output] = ($this->artisan)(['command' => 'registry:uninstall', 'extension' => 'extension_widgets', '--json' => true]);

    expect($code)->toBe(1);
    expect(json_decode($output, true))->toBe(['error' => 'This extension is not installed.']);
    expect($this->bus->dispatched)->toBe([]);
});

test('registry:uninstall and registry:update queue jobs for installed extensions', function () {
    $this->sandbox->recorder->installs['company-1']['ext-uuid-1'] = ['version' => '1.0.0'];

    [$uninstallCode] = ($this->artisan)(['command' => 'registry:uninstall', 'extension' => 'extension_widgets', '--company' => 'company-1']);
    $uninstall       = $this->store->get($this->bus->dispatched[0]->jobId);
    $this->store->update($uninstall['id'], ['status' => 'completed']);

    [$updateCode] = ($this->artisan)(['command' => 'registry:update', 'extension' => 'extension_widgets', '--release' => '1.2.0']);
    $update       = $this->store->get($this->bus->dispatched[1]->jobId);

    expect([$uninstallCode, $updateCode])->toBe([0, 0]);
    expect($uninstall)->toMatchArray(['action' => 'uninstall', 'company' => 'company-1', 'version' => null]);
    expect($update)->toMatchArray(['action' => 'update', 'version' => '1.2.0']);
});

test('registry:job --json prints the job record with its queue position', function () {
    ($this->artisan)(['command' => 'registry:install', 'extension' => 'extension_widgets']);
    $first = $this->bus->dispatched[0]->jobId;
    $this->store->update($first, ['status' => 'running', 'stage' => 'composer', 'progress' => 40, 'message' => 'Installing Composer package', 'log' => ['Resolving acme/widgets-api', 'Installing acme/widgets-api (1.0.0)']]);
    $this->sandbox->releases->add(new Fleetbase\RegistryBridge\Support\Installer\Release(new Fleetbase\RegistryBridge\Support\Installer\ExtensionRef('ext-uuid-0', 'extension_zero', 'Zero'), '1.0.0', 'acme/zero', '1.0.0'));
    ($this->artisan)(['command' => 'registry:install', 'extension' => 'extension_zero']);
    $second = $this->bus->dispatched[1]->jobId;

    [$code, $output]   = ($this->artisan)(['command' => 'registry:job', 'id' => $first, '--json' => true]);
    [, $queuedOutput]  = ($this->artisan)(['command' => 'registry:job', 'id' => $second, '--json' => true]);

    $job = json_decode($output, true);
    expect($code)->toBe(0);
    expect($job)->toBe($this->store->find($first));
    expect($job)->toMatchArray(['id' => $first, 'action' => 'install', 'status' => 'running', 'stage' => 'composer', 'progress' => 40, 'message' => 'Installing Composer package', 'position' => 0]);
    expect($job['log'])->toBe(['Resolving acme/widgets-api', 'Installing acme/widgets-api (1.0.0)']);
    expect(json_decode($queuedOutput, true))->toMatchArray(['id' => $second, 'status' => 'queued', 'position' => 1]);
    expect($output)->toContain('"stage": "composer"');
});

test('registry:job prints a summary with the stage and log tail', function () {
    ($this->artisan)(['command' => 'registry:install', 'extension' => 'extension_widgets']);
    $id = $this->bus->dispatched[0]->jobId;
    $this->store->update($id, ['status' => 'failed', 'stage' => 'composer', 'failed_stage' => 'composer', 'progress' => 40, 'message' => 'composer require failed', 'log' => array_map(fn ($n) => 'line ' . $n, range(1, 12))]);

    [$code, $output] = ($this->artisan)(['command' => 'registry:job', 'id' => $id]);

    expect($code)->toBe(0);
    expect($output)->toContain('Job ' . $id . ': install of Widgets')
        ->toContain('Status:   failed')
        ->toContain('Stage:    composer (failed)')
        ->toContain('Progress: 40%')
        ->toContain('Message:  composer require failed')
        ->toContain('line 3')
        ->toContain('line 12')
        ->not->toContain('line 2' . PHP_EOL);
});

test('registry:job exits non-zero for an unknown job, as JSON when asked', function () {
    [$code, $output]     = ($this->artisan)(['command' => 'registry:job', 'id' => 'missing-job', '--json' => true]);
    [$textCode, $text]   = ($this->artisan)(['command' => 'registry:job', 'id' => 'missing-job']);

    expect($code)->toBe(1);
    expect(json_decode($output, true))->toBe(['error' => 'Installer job missing-job was not found.']);
    expect($textCode)->toBe(1);
    expect($text)->toContain('Installer job missing-job was not found.');
});
