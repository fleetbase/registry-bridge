<?php

namespace Fleetbase\RegistryBridge\Console\Commands;

use Fleetbase\RegistryBridge\Exceptions\RegistryException;
use Fleetbase\RegistryBridge\Support\CloudInstallSync;
use Fleetbase\RegistryBridge\Support\RegistryClient;
use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Illuminate\Console\Command;

/**
 * Cutover step on the cloud console's API, after the import and the registration: record locally what each company
 * had installed (see `CloudInstallSync`), so consoles keep loading those engines. Safe to run again.
 */
class SyncCloudInstalls extends Command
{
    protected $signature = 'registry:cloud-sync-installs
                            {--dry-run : Count what would be written, write nothing}';

    protected $description = 'Record locally what each cloud company has installed, from the Registry Service import.';

    public function handle(): int
    {
        $config = RegistryConfig::current();
        if (!$config->actsForCompanies() || !$config->isRegistered()) {
            $this->error('Only the registered cloud instance can sync installs: run registry:cloud-register first.');

            return self::FAILURE;
        }

        try {
            $counts = (new CloudInstallSync(RegistryClient::fromConfig($config), $config->serviceUrl()))->run((bool) $this->option('dry-run'));
        } catch (RegistryException $e) {
            $this->error('Sync failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Created', 'Updated', 'Unchanged', 'Skipped (unknown company)', 'Companies'], [[$counts['created'], $counts['updated'], $counts['unchanged'], $counts['skipped'], $counts['companies']]]);
        $this->line($this->option('dry-run') ? 'Dry run: nothing was written.' : 'Installs synced.');

        return self::SUCCESS;
    }
}
