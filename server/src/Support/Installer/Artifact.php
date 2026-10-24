<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * A file a release needs downloaded before install.
 *
 * `uri` is an absolute path, a file:// or http(s):// URL, or
 * `disk://<disk>/<path>` for a file on a Laravel filesystem disk. `checksum`
 * is `sha256-<hex|base64>`, `sha512-<hex|base64>` (the npm integrity form),
 * `<algo>:<hex>` or a bare sha256 hex digest.
 */
class Artifact
{
    public const DIST = 'dist';

    public function __construct(
        public readonly string $role,
        public readonly string $uri,
        public readonly ?string $checksum = null,
        public readonly ?string $filename = null,
    ) {
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return ['role' => $this->role, 'uri' => $this->uri, 'checksum' => $this->checksum, 'filename' => $this->filename];
    }
}
