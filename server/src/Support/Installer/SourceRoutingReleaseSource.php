<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

use Fleetbase\RegistryBridge\Support\Installer\Contracts\ReleaseSource;

/**
 * Several release sources behind one: `find()` asks them in order and the
 * first match wins; `resolve()` goes back to the source that found the
 * extension (`ExtensionRef::$source`).
 *
 * When a source fails (the Registry Service is down) the others are still
 * asked; the failure is only reported when nobody found the extension.
 */
class SourceRoutingReleaseSource implements ReleaseSource
{
    /**
     * @param array<string, ReleaseSource> $sources keyed by ExtensionRef source, in lookup order
     */
    public function __construct(private readonly array $sources)
    {
    }

    public function find(string $extension): ?ExtensionRef
    {
        $failure = null;
        foreach ($this->sources as $source) {
            try {
                $ref = $source->find($extension);
            } catch (InstallerException $e) {
                $failure ??= $e;
                continue;
            }

            if ($ref !== null) {
                return $ref;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        return null;
    }

    public function resolve(ExtensionRef $extension, ?string $version = null): Release
    {
        $source = $this->sources[$extension->source] ?? $this->sources[ExtensionRef::LOCAL] ?? null;
        if ($source === null) {
            throw new ReleaseNotFoundException(sprintf('No release source for %s extensions.', $extension->source));
        }

        return $source->resolve($extension, $version);
    }
}
