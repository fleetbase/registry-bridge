<?php

namespace Fleetbase\RegistryBridge\Console\Commands;

class UpdateExtension extends InstallerCommand
{
    protected $signature = 'registry:update
                            {extension : Public id, slug or package name, optionally with @version}
                            {--release= : Version to update to (artisan reserves --version); defaults to the current release}
                            {--company= : Company uuid or public id (optional on single-company instances)}
                            {--sync : Run now in this process instead of queueing}
                            {--json : Print the job record as JSON}';

    protected $description = 'Queue an extension update on the installer queue.';

    protected string $action = 'update';
}
