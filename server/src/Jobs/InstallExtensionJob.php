<?php

namespace Fleetbase\RegistryBridge\Jobs;

use Fleetbase\RegistryBridge\Support\Installer\ExtensionInstaller;
use Fleetbase\RegistryBridge\Support\Installer\InstallerQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs one installer job record (install, uninstall or update) on the
 * `installer` queue. All logic lives in InstallerQueue::process and
 * ExtensionInstaller; the job only carries the record id.
 *
 * Run a worker with a timeout above the longest install, for example:
 *   php artisan queue:work --queue=installer --timeout=7200
 */
class InstallExtensionJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * Seconds before the worker kills the job.
     *
     * @var int
     */
    public $timeout;

    /**
     * Mark the job failed (and call failed()) when it times out.
     *
     * @var bool
     */
    public $failOnTimeout = true;

    public function __construct(public string $jobId, string $queue = 'installer', ?string $connection = null, int $timeout = 7200)
    {
        $this->timeout = $timeout;
        $this->onQueue($queue);
        if ($connection) {
            $this->onConnection($connection);
        }
    }

    /**
     * Waiting for the instance lock releases the job back to the queue, which
     * counts as an attempt; bound retries by time instead.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return new \DateTimeImmutable('+12 hours');
    }

    public function handle(InstallerQueue $queue, ExtensionInstaller $installer): void
    {
        $queue->process($this->jobId, $installer, fn (int $delay) => $this->release($delay));
    }

    public function failed(?\Throwable $exception = null): void
    {
        app(InstallerQueue::class)->interrupt($this->jobId, $exception ? $exception->getMessage() : 'the job failed');
    }
}
