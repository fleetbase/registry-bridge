<?php

namespace Fleetbase\RegistryBridge\Console\Commands;

use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Fleetbase\RegistryBridge\Support\Utils;
use Illuminate\Console\Command;

class Initialize extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'registry:init';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Runs initialization for registry bridge.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Installs from the Registry Service inject credentials per process; nothing is written to disk
        if (RegistryConfig::current()->usesService()) {
            $this->info('Registry Service configured; skipping .npmrc and auth.json setup.');

            return 0;
        }

        Utils::bootRegistryAuth(true);

        return 0;
    }
}
