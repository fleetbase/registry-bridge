<?php

namespace Fleetbase\RegistryBridge\Console\Commands;

use Fleetbase\RegistryBridge\Exceptions\RegistryException;
use Fleetbase\RegistryBridge\Support\AutoUpdate\AutoUpdater;
use Illuminate\Console\Command;

/**
 * Queue updates for installed extensions whose auto-update policy is `patch` or `minor` (set per install
 * on the Installed page). Scheduled by the service provider (`registry-bridge.auto_update.schedule`); safe
 * to run by hand. Updates go through the queued installer, like the console's Update button.
 */
class AutoUpdateExtensions extends Command
{
    protected $signature = 'registry:auto-update
                            {--dry-run : Show what would be queued without queueing it}
                            {--retry-failed : Retry versions whose last automatic update failed}
                            {--json : Print the results as JSON}';

    protected $description = 'Queue updates allowed by each installed extension\'s auto-update policy.';

    public function handle(AutoUpdater $updater): int
    {
        try {
            $results = $updater->run((bool) $this->option('dry-run'), (bool) $this->option('retry-failed'));
        } catch (RegistryException $e) {
            $this->error('The registry service could not be asked for updates: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($results === []) {
            $this->info('No installed extension has an auto-update policy.');
        } else {
            $this->table(
                ['Extension', 'Policy', 'Installed', 'Target', 'Result', 'Job', 'Note'],
                array_map(fn (array $row) => [$row['name'], $row['policy'], $row['installed'] ?? '-', $row['target'] ?? '-', $row['result'], $row['job'] ?? '-', $row['message'] ?? ''], $results)
            );
        }

        $errors = array_filter($results, fn (array $row) => $row['result'] === AutoUpdater::ERROR);

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
