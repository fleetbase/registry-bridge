<?php

namespace Fleetbase\RegistryBridge\Console\Commands;

use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Fleetbase\RegistryBridge\Support\RegistryMaintenance;
use Illuminate\Console\Command;

/**
 * `php artisan registry:maintenance on` freezes checkout, install, update and uninstall in the console for the
 * cutover window and shows the message as a banner on the Extensions pages; `off` lifts it; `status` shows it.
 */
class ManageMaintenance extends Command
{
    protected $signature = 'registry:maintenance
                            {action=status : on, off or status}
                            {--message= : Banner and refusal message (default: the move-in-progress message)}';

    protected $description = 'Turn the extensions cutover freeze (no checkout, install or uninstall) on or off.';

    /**
     * The maintenance writer is resolved from the container so tests can replace its settings writer.
     */
    public function handle(RegistryMaintenance $maintenance): int
    {
        $action = strtolower((string) $this->argument('action'));
        if (!in_array($action, ['on', 'off', 'status'], true)) {
            $this->error("Unknown action [{$action}]: use on, off or status.");

            return self::INVALID;
        }

        if ($action === 'on') {
            $message = $this->option('message');
            $maintenance::enable(is_string($message) ? $message : null);
        } elseif ($action === 'off') {
            $maintenance::disable();
        }

        $maintenance = RegistryConfig::current()->maintenance();
        $this->line(sprintf('Maintenance: %s%s', $maintenance['enabled'] ? 'ON' : 'off', $maintenance['since'] && $maintenance['enabled'] ? ' since ' . $maintenance['since'] : ''));
        if ($maintenance['enabled']) {
            $this->line('Message: ' . $maintenance['message']);
        }

        if ($action === 'off' && $maintenance['forced']) {
            $this->warn('REGISTRY_MAINTENANCE=true still forces it on; unset it and restart the API.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
