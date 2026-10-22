<?php

namespace Fleetbase\RegistryBridge\Tests\Installer\Fakes;

use Fleetbase\RegistryBridge\Support\Installer\Contracts\ReleaseSource;
use Fleetbase\RegistryBridge\Support\Installer\ExtensionRef;
use Fleetbase\RegistryBridge\Support\Installer\Release;
use Fleetbase\RegistryBridge\Support\Installer\ReleaseNotFoundException;

class FakeReleaseSource implements ReleaseSource
{
    /** @var array<string, ExtensionRef> */
    public array $extensions = [];

    /** @var array<string, array<string, Release>> releases by extension uuid and version ('' = current) */
    public array $releases = [];

    /** @var array<int, array{0: string, 1: ?string}> */
    public array $resolved = [];

    public function add(Release $release, bool $current = true): self
    {
        $ref                                                 = $release->extension;
        $this->extensions[$ref->uuid]                        = $ref;
        $this->releases[$ref->uuid][$release->version]       = $release;
        if ($current) {
            $this->releases[$ref->uuid][''] = $release;
        }

        return $this;
    }

    public function find(string $extension): ?ExtensionRef
    {
        foreach ($this->extensions as $ref) {
            if (in_array($extension, [$ref->uuid, $ref->id, $ref->name], true)) {
                return $ref;
            }
        }

        return null;
    }

    public function resolve(ExtensionRef $extension, ?string $version = null): Release
    {
        $this->resolved[] = [$extension->uuid, $version];
        $release          = $this->releases[$extension->uuid][$version ?? ''] ?? null;
        if ($release === null) {
            throw new ReleaseNotFoundException(sprintf('No release %s for %s.', $version ?? 'current', $extension->name));
        }

        return $release;
    }
}
