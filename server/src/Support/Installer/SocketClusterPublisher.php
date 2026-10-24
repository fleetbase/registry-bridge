<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

use Fleetbase\RegistryBridge\Support\Installer\Contracts\ProgressPublisher;
use Fleetbase\Support\SocketCluster\SocketClusterService;

class SocketClusterPublisher implements ProgressPublisher
{
    public function publish(string $channel, array $payload): void
    {
        SocketClusterService::publish($channel, $payload);
    }
}
