<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

use Fleetbase\RegistryBridge\Jobs\InstallExtensionJob;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\LockProvider;

/**
 * Serialises installer jobs on an instance.
 *
 * Requests are recorded in the job store and dispatched to the `installer`
 * queue. A worker runs a job only while it holds the instance lock (owned by
 * the job id); a job that finds the lock taken is released back to the queue.
 * A record still `running` with a fresh heartbeat is a duplicate delivery
 * (the queue's retry_after is shorter than an install) and is left alone; a
 * stale or `interrupted` one belonged to a worker that died, so its snapshot
 * is rolled back.
 */
class InstallerQueue
{
    public const LOCK = InstallerJobStore::PREFIX . 'lock';

    public function __construct(
        private readonly InstallerJobStore $store,
        private readonly LockProvider $locks,
        private readonly Dispatcher $bus,
        private readonly string $queue = 'installer',
        private readonly ?string $connection = null,
        private readonly int $lockSeconds = 10800,
        private readonly int $retryDelay = 15,
        private readonly int $staleAfter = 1800,
        private readonly int $timeout = 7200,
    ) {
    }

    /**
     * Record a request and dispatch its job.
     *
     * @param array<string, mixed> $attributes action, extension, version, company, user
     *
     * @return array<string, mixed> the record with its queue position
     */
    public function enqueue(array $attributes): array
    {
        $record = $this->store->create($attributes);
        $this->store->enqueue($record['id']);
        $this->dispatch($record['id']);

        return $this->store->find($record['id']) ?? $record;
    }

    public function dispatch(string $id): void
    {
        $this->bus->dispatch(new InstallExtensionJob($id, $this->queue, $this->connection, $this->timeout));
    }

    /**
     * Record a request and run it in this process (artisan --sync, or the
     * controller with async installs turned off). Refuses when another job
     * holds the lock instead of waiting for it.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    public function runNow(array $attributes, ExtensionInstaller $installer): array
    {
        $record = $this->store->create($attributes);
        $this->store->enqueue($record['id']);

        return $this->process($record['id'], $installer, null) ?? $record;
    }

    /**
     * Run a queued job (InstallExtensionJob::handle).
     *
     * @param callable(int $delay): void|null $release puts the job back on the queue; null in sync mode
     *
     * @return array<string, mixed>|null the record afterwards
     */
    public function process(string $id, ExtensionInstaller $installer, ?callable $release): ?array
    {
        $record = $this->store->get($id);
        if ($record === null || in_array($record['status'] ?? null, InstallerJobStore::FINISHED, true)) {
            return $record;
        }

        $status = $record['status'] ?? 'queued';
        if ($status === 'running' && !$this->isStale($record)) {
            return $record;
        }

        $interrupted = in_array($status, ['running', 'interrupted'], true);
        if ($interrupted) {
            // The dead worker's lock is ours (owner = job id); free it.
            $this->locks->restoreLock(self::LOCK, $id)->release();
        }

        $lock = $this->locks->lock(self::LOCK, $this->lockSeconds, $id);
        if (!$lock->get()) {
            if ($release === null) {
                $this->store->update($id, ['status' => 'failed', 'message' => 'Another installer job is running on this instance.', 'finished_at' => date(DATE_ATOM)]);
                $this->store->dequeue($id);
                throw new InstallerException('Another installer job is running on this instance; try again when it finishes, or queue this one without --sync.');
            }
            $release($this->retryDelay);

            return $this->store->find($id);
        }

        try {
            if ($interrupted) {
                $result = $installer->recover($record, $record['message'] ?? 'The installer stopped before this job finished (worker restart or timeout).');
            } else {
                $now = date(DATE_ATOM);
                $this->store->update($id, ['status' => 'running', 'started_at' => $now, 'heartbeat_at' => time()]);
                $result = $installer->run($record, function (array $patch) use ($id): void {
                    $this->store->update($id, [...$patch, 'heartbeat_at' => time()]);
                });
            }

            return $this->finish($id, $result);
        } finally {
            $lock->release();
            $this->store->dequeue($id);
        }
    }

    /**
     * Called from the job's failed() hook (timeout, worker crash, retries
     * exhausted). A job that started is marked interrupted and dispatched
     * again so a worker rolls it back; one that never started fails.
     */
    public function interrupt(string $id, string $reason): void
    {
        $record = $this->store->get($id);
        if ($record === null || in_array($record['status'] ?? null, InstallerJobStore::FINISHED, true)) {
            return;
        }

        if (($record['status'] ?? null) === 'queued') {
            $this->store->update($id, ['status' => 'failed', 'message' => 'The job never got the installer lock: ' . $reason, 'finished_at' => date(DATE_ATOM)]);
            $this->store->dequeue($id);

            return;
        }

        $this->store->update($id, ['status' => 'interrupted', 'message' => 'The installer stopped before this job finished: ' . $reason]);
        $this->dispatch($id);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>|null
     */
    private function finish(string $id, array $result): ?array
    {
        $failed = ($result['status'] ?? null) === 'failed';

        return $this->store->update($id, [
            'status'       => $failed ? 'failed' : 'completed',
            'progress'     => 100,
            'message'      => $result['message'] ?? null,
            'failed_stage' => $result['failed_stage'] ?? null,
            'log'          => $result['log'] ?? [],
            'result'       => $result,
            'finished_at'  => date(DATE_ATOM),
        ]);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function isStale(array $record): bool
    {
        return (time() - (int) ($record['heartbeat_at'] ?? 0)) > $this->staleAfter;
    }
}
