<?php

namespace Fleetbase\RegistryBridge\Console\Commands;

class InstallExtension extends InstallerCommand
{
    protected $signature = 'registry:install
                            {extension : Public id, slug or package name, optionally with @version}
                            {--release= : Version to install (artisan reserves --version)}
                            {--company= : Company uuid or public id (optional on single-company instances)}
                            {--sync : Run now in this process instead of queueing}
                            {--json : Print the job record as JSON}';

    protected $description = 'Queue an extension install on the installer queue.';

    protected string $action = 'install';
}
