<?php

namespace Fleetbase\RegistryBridge\Support\Installer\Contracts;

/**
 * Publishes install progress to the console (SocketCluster in production).
 */
interface ProgressPublisher
{
    /**
     * @param array<string, mixed> $payload
     */
    public function publish(string $channel, array $payload): void;
}
