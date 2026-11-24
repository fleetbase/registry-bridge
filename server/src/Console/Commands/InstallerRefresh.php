<?php

namespace Fleetbase\RegistryBridge\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * What the installer runs in the API after Composer changed its packages, in one process: package discovery,
 * the cache commands (`registry-bridge.installer.cache_commands`) and the Octane reload. Each `php artisan`
 * boots the whole API, which takes seconds (tens of seconds on a loaded or emulated Docker host), so one boot
 * instead of six or seven is most of what the installer's last stage costs.
 *
 * Stops at the first command that fails and exits non-zero, as the separate commands did. A failed Octane
 * reload is reported and never fails the run: the workers load the change when they next restart.
 */
class InstallerRefresh extends Command
{
    protected $signature = 'registry:installer-refresh
                            {--discover : Run package:discover first (Composer ran without its scripts)}
                            {--run=* : An artisan command to run, with its arguments (for example "config:clear")}
                            {--octane : Reload the Octane workers last}';

    protected $description = 'Refresh the API after the extension installer changed its packages (internal).';

    protected $hidden = true;

    public function handle(): int
    {
        $commands = $this->option('discover') ? ['package:discover'] : [];
        foreach ((array) $this->option('run') as $command) {
            if (is_string($command) && trim($command) !== '') {
                $commands[] = trim($command);
            }
        }

        foreach ($commands as $command) {
            $started = microtime(true);
            $this->line('$ php artisan ' . $command);
            try {
                $exit = Artisan::call($command, [], $this->output);
            } catch (\Throwable $e) {
                $this->error(sprintf('%s failed: %s', $command, $e->getMessage()));

                return self::FAILURE;
            }
            if ($exit !== 0) {
                $this->error(sprintf('%s exited with code %d.', $command, $exit));

                return self::FAILURE;
            }
            $this->line(sprintf('  (%.1fs)', microtime(true) - $started));
        }

        if ($this->option('octane')) {
            $this->line('$ php artisan octane:reload');
            try {
                $exit = Artisan::call('octane:reload', [], $this->output);
                if ($exit !== 0) {
                    $this->warn('Octane was not reloaded; its workers load the change when they next restart.');
                }
            } catch (\Throwable $e) {
                $this->warn('Octane was not reloaded (' . $e->getMessage() . '); its workers load the change when they next restart.');
            }
        }

        return self::SUCCESS;
    }
}
