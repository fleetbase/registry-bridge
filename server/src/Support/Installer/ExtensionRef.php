<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * The extension an install job is about, as stored in the job record.
 *
 * `source` says which ReleaseSource found it: `local` for this instance's
 * registry tables, `registry-service` for the Registry Service (where `uuid`
 * and `id` are both the service's `ext_` id).
 */
class ExtensionRef
{
    public const LOCAL = 'local';

    public const REGISTRY_SERVICE = 'registry-service';

    public function __construct(
        public readonly string $uuid,
        public readonly string $id,
        public readonly string $name,
        public readonly string $source = self::LOCAL,
    ) {
    }

    public function isFromRegistryService(): bool
    {
        return $this->source === self::REGISTRY_SERVICE;
    }

    /**
     * @return array{uuid: string, id: string, name: string, source: string}
     */
    public function toArray(): array
    {
        return ['uuid' => $this->uuid, 'id' => $this->id, 'name' => $this->name, 'source' => $this->source];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $source = (string) ($data['source'] ?? '');

        return new self((string) ($data['uuid'] ?? ''), (string) ($data['id'] ?? ''), (string) ($data['name'] ?? ''), $source !== '' ? $source : self::LOCAL);
    }
}
