<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

use Fleetbase\Models\File;
use Fleetbase\RegistryBridge\Models\RegistryExtension;
use Fleetbase\RegistryBridge\Models\RegistryExtensionBundle;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\ReleaseSource;

/**
 * Releases from this instance's registry tables: the extension's current
 * bundle, or the bundle with the requested version. The packages come from
 * the bundle's composer.json and package.json meta. A prebuilt engine dist is
 * attached when the bundle meta carries `engine_dist` ({ file_uuid, checksum });
 * nothing writes that yet, the Registry Service source will supply it.
 */
class LocalBundleReleaseSource implements ReleaseSource
{
    public function find(string $extension): ?ExtensionRef
    {
        $model = RegistryExtension::where(function ($query) use ($extension) {
            $query->where('uuid', $extension)->orWhere('public_id', $extension)->orWhere('slug', $extension);
        })->first() ?? RegistryExtension::findByPackageName($extension);

        return $model ? new ExtensionRef($model->uuid, (string) $model->public_id, (string) $model->name) : null;
    }

    public function resolve(ExtensionRef $extension, ?string $version = null): Release
    {
        $model = RegistryExtension::where('uuid', $extension->uuid)->first();
        if (!$model) {
            throw new ReleaseNotFoundException(sprintf('Extension %s no longer exists.', $extension->name));
        }

        $bundle = $version === null
            ? $model->currentBundle
            : RegistryExtensionBundle::where('extension_uuid', $model->uuid)->where('version', $version)->first();
        if (!$bundle) {
            throw new ReleaseNotFoundException($version === null ? sprintf('%s has no published bundle.', $extension->name) : sprintf('%s has no bundle for version %s.', $extension->name, $version));
        }

        $meta     = (array) ($bundle->meta ?? []);
        $composer = data_get($meta, 'composer.json');
        $package  = data_get($meta, 'package.json');

        return new Release(
            extension: $extension,
            version: static::stringOrNull($bundle->version ?? data_get($package, 'version') ?? data_get($composer, 'version')) ?? 'latest',
            composerPackage: static::stringOrNull(data_get($composer, 'name')),
            composerConstraint: static::stringOrNull(data_get($composer, 'version')),
            enginePackage: static::stringOrNull(data_get($package, 'name')),
            engineConstraint: static::stringOrNull(data_get($package, 'version')),
            artifacts: $this->artifacts($meta),
            source: 'local',
        );
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<int, Artifact>
     */
    private function artifacts(array $meta): array
    {
        $dist = data_get($meta, 'engine_dist');
        $file = is_array($dist) && isset($dist['file_uuid']) ? File::where('uuid', $dist['file_uuid'])->first() : null;
        if (!$file) {
            return [];
        }

        return [new Artifact(Artifact::DIST, sprintf('disk://%s/%s', $file->getDisk(), ltrim((string) $file->path, '/')), static::stringOrNull($dist['checksum'] ?? null), static::stringOrNull($file->original_filename))];
    }

    /**
     * Bundle meta is user-supplied JSON: versions may be numbers, names may be missing.
     */
    protected static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : null;
    }
}
