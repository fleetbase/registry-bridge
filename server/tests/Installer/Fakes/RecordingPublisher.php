<?php

namespace Fleetbase\RegistryBridge\Tests\Installer\Fakes;

use Fleetbase\RegistryBridge\Support\Installer\Contracts\ProgressPublisher;

class RecordingPublisher implements ProgressPublisher
{
    /** @var array<int, array{channel: string, payload: array<string, mixed>}> */
    public array $events = [];

    public function publish(string $channel, array $payload): void
    {
        $this->events[] = ['channel' => $channel, 'payload' => $payload];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function payloads(): array
    {
        return array_column($this->events, 'payload');
    }

    /**
     * Distinct steps in the order they first appeared.
     *
     * @return array<int, string>
     */
    public function steps(): array
    {
        return array_values(array_unique(array_filter(array_column($this->payloads(), 'step'))));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function last(): ?array
    {
        return $this->events === [] ? null : end($this->events)['payload'];
    }
}
