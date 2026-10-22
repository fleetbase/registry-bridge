<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * The extension an install job is about, as stored in the job record.
 */
class ExtensionRef
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $id,
        public readonly string $name,
    ) {
    }

    /**
     * @return array{uuid: string, id: string, name: string}
     */
    public function toArray(): array
    {
        return ['uuid' => $this->uuid, 'id' => $this->id, 'name' => $this->name];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['uuid'] ?? ''), (string) ($data['id'] ?? ''), (string) ($data['name'] ?? ''));
    }
}
