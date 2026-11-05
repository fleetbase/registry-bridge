<?php

namespace Fleetbase\RegistryBridge\Console\Commands;

class UninstallExtension extends InstallerCommand
{
    protected $signature = 'registry:uninstall
                            {extension : Public id, slug or package name}
                            {--company= : Company uuid or public id (optional on single-company instances)}
                            {--sync : Run now in this process instead of queueing}
                            {--json : Print the job record as JSON}';

    protected $description = 'Queue an extension uninstall on the installer queue.';

    protected string $action = 'uninstall';
}
