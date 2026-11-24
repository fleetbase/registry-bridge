<?php

namespace Fleetbase\RegistryBridge\Console\Commands;

use Fleetbase\RegistryBridge\Support\CloudRegistration;
use Illuminate\Console\Command;

/**
 * Run by deploy scripts when the API starts. Installs from the Registry Service inject package credentials per
 * process, so there is no `.npmrc` or `auth.json` to write any more; on Fleetbase Cloud
 * (`REGISTRY_CLOUD_AUTO_REGISTER=true`) it registers this API with the service as the cloud instance.
 *
 * It never fails the boot: a registration that does not succeed is reported, and the scheduler tries again every
 * five minutes (`registry:cloud-register`).
 */
class Initialize extends Command
{
    protected $signature = 'registry:init';

    protected $description = 'Prepare the registry bridge on boot: registers the cloud instance with the Registry Service on Fleetbase Cloud.';

    public function handle()
    {
        try {
            $result = CloudRegistration::ensure();
        } catch (\Throwable $e) {
            $this->warn('Cloud registration with the Registry Service failed: ' . $e->getMessage() . ' The scheduler retries it every five minutes.');

            return 0;
        }

        if ($result['status'] === CloudRegistration::STATUS_DISABLED) {
            $this->info('Nothing to initialize: installs from the Registry Service carry their own credentials.');

            return 0;
        }

        $this->info($result['message']);

        return 0;
    }
}
