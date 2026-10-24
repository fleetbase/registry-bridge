<?php

namespace Fleetbase\RegistryBridge\Console\Commands;

use Illuminate\Console\Command;

/**
 * Kept for deploy scripts that still call it. Installs from the Registry Service inject package
 * credentials per process, so there is no `.npmrc` or `auth.json` to write any more.
 */
class Initialize extends Command
{
    protected $signature = 'registry:init';

    protected $description = 'No-op: the registry bridge needs no initialization with the Registry Service.';

    public function handle()
    {
        $this->info('Nothing to initialize: installs from the Registry Service carry their own credentials.');

        return 0;
    }
}
