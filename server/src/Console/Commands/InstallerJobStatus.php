<?php

namespace Fleetbase\RegistryBridge\Console\Commands;

use Fleetbase\RegistryBridge\Support\Installer\InstallerJobStore;
use Illuminate\Console\Command;

/**
 * Status of a queued installer job, for `flb` to follow the jobs registry:install, registry:update and
 * registry:uninstall print.
 */
class InstallerJobStatus extends Command
{
    /**
     * Log lines shown in the human summary.
     */
    public const LOG_TAIL = 10;

    protected $signature = 'registry:job
                            {id : Installer job id}
                            {--json : Print the job record as JSON}';

    protected $description = 'Show the status of an installer job.';

    public function handle(InstallerJobStore $jobs): int
    {
        $id  = (string) (is_string($this->argument('id')) ? $this->argument('id') : '');
        $job = $id !== '' ? $jobs->find($id) : null;

        if ($job === null) {
            $message = sprintf('Installer job %s was not found.', $id);
            if ($this->option('json')) {
                $this->line((string) json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($message);
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->describe($job);
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $job
     */
    protected function describe(array $job): void
    {
        $extension = is_array($job['extension'] ?? null) ? $job['extension'] : [];
        $status    = static::text($job['status'] ?? null, 'unknown');

        $this->line(sprintf('Job %s: %s of %s', static::text($job['id'] ?? null), static::text($job['action'] ?? null, 'install'), static::text($extension['name'] ?? $extension['id'] ?? null, 'extension')));
        $this->line(sprintf('Status:   %s', $status));
        if ($status === 'failed') {
            $this->line(sprintf('Stage:    %s (failed)', static::text($job['failed_stage'] ?? $job['stage'] ?? null, 'unknown')));
        } elseif (static::text($job['stage'] ?? null) !== '') {
            $this->line(sprintf('Stage:    %s', static::text($job['stage'])));
        }
        $this->line(sprintf('Progress: %d%%', is_numeric($job['progress'] ?? null) ? (int) $job['progress'] : 0));
        if ($status === 'queued' && is_int($job['position'] ?? null)) {
            $this->line(sprintf('Position: %d job(s) ahead', $job['position']));
        }
        if (static::text($job['message'] ?? null) !== '') {
            $this->line(sprintf('Message:  %s', static::text($job['message'])));
        }

        $log = is_array($job['log'] ?? null) ? array_slice($job['log'], -self::LOG_TAIL) : [];
        if ($log !== []) {
            $this->line('Log:');
            foreach ($log as $line) {
                $this->line('  ' . static::text($line));
            }
        }
    }

    protected static function text(mixed $value, string $default = ''): string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }
}
