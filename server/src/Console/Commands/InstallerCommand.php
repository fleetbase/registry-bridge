<?php

namespace Fleetbase\RegistryBridge\Console\Commands;

use Fleetbase\RegistryBridge\Support\Installer\InstallerException;
use Fleetbase\RegistryBridge\Support\Installer\InstallerService;
use Illuminate\Console\Command;

/**
 * Shared handling for registry:install, registry:uninstall and registry:update.
 *
 * The version is `--release=1.2.0` or an `@1.2.0` suffix on the extension:
 * artisan reserves `--version` for its own version banner.
 */
abstract class InstallerCommand extends Command
{
    /**
     * install, uninstall or update.
     */
    protected string $action;

    public function handle(InstallerService $installer): int
    {
        [$extension, $version] = $this->extensionAndVersion();

        try {
            $job = $installer->request($this->action, $extension, $version, $this->stringOption('company'), null, (bool) $this->option('sync'));
        } catch (InstallerException $e) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->describe($job);
        }

        return ($job['status'] ?? null) === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    protected function extensionAndVersion(): array
    {
        $extension = (string) (is_string($this->argument('extension')) ? $this->argument('extension') : '');
        $version   = $this->hasOption('release') ? $this->stringOption('release') : null;

        // `@` after the first character separates a version (`@acme/engine@1.2.0`, `pallet@0.0.2`).
        $at = strrpos($extension, '@');
        if ($at !== false && $at > 0) {
            $version ??= substr($extension, $at + 1) ?: null;
            $extension = substr($extension, 0, $at);
        }

        return [$extension, $version];
    }

    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $job
     */
    protected function describe(array $job): void
    {
        $name = $job['extension']['name'] ?? $job['extension']['id'] ?? 'extension';

        switch ($job['status'] ?? null) {
            case 'completed':
                $this->info(sprintf('%s of %s completed (job %s).', ucfirst($this->action), $name, $job['id']));
                break;

            case 'failed':
                $this->error(sprintf('%s of %s failed at stage "%s": %s', ucfirst($this->action), $name, $job['failed_stage'] ?? $job['stage'] ?? 'unknown', $job['message'] ?? 'unknown error'));
                foreach ($job['log'] ?? [] as $line) {
                    $this->line('  ' . $line);
                }
                break;

            default:
                $position = $job['position'] ?? null;
                $this->info(sprintf('%s of %s queued as job %s%s.', ucfirst($this->action), $name, $job['id'], !empty($job['duplicate']) ? ' (already queued)' : ''));
                $this->line($position ? sprintf('%d job(s) ahead of it in the installer queue.', $position) : 'It is next in the installer queue.');
                $this->line('Run a worker for the queue if none is running: php artisan queue:work --queue=installer --timeout=7200');
        }
    }
}
