<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

use Fleetbase\RegistryBridge\Exceptions\RegistryException;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\ReleaseSource;
use Fleetbase\RegistryBridge\Support\PackageAuth;
use Fleetbase\RegistryBridge\Support\RegistryClient;
use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Fleetbase\RegistryBridge\Support\ServiceCatalog;

/**
 * Releases from the Registry Service catalog (`/v1/catalog/extensions`).
 *
 * An extension is found by its `ext_` id, slug, or npm or Composer package
 * name. The release carries the service's package locations: the Composer
 * repository to add and the engine tarball URL, so `pnpm add` never remaps the
 * engine's npm scope. Credentials are not part of the release; they come from
 * ServicePackageCredentials per process.
 *
 * Licences are checked by the instance API when the install starts (see
 * ServiceInstallReporter), not here.
 */
class ServiceReleaseSource implements ReleaseSource
{
    /** @var (callable(string): ?array<string, mixed>)|null */
    private $installedListing;

    /**
     * @param callable(string $extensionId): ?array<string, mixed>|null $installedListing listing-shaped local record of an installed
     *                                                                                    extension, used when the service no longer lists it
     */
    public function __construct(
        private readonly RegistryClient $client,
        private readonly RegistryConfig $config,
        ?callable $installedListing = null,
    ) {
        $this->installedListing = $installedListing;
    }

    public function find(string $extension): ?ExtensionRef
    {
        if (!$this->config->usesService() || trim($extension) === '') {
            return null;
        }

        try {
            $resolved = ServiceCatalog::lookup($extension, $this->client);
        } catch (RegistryException $e) {
            throw new InstallerException('The registry service could not be reached: ' . $e->getMessage(), 0, $e);
        }

        $listing = $resolved['extension'] ?? $this->localListing($extension);

        return $listing ? $this->reference($listing) : null;
    }

    public function resolve(ExtensionRef $extension, ?string $version = null): Release
    {
        try {
            $listing = $this->client->catalogExtension($extension->id);
        } catch (RegistryException $e) {
            throw new ReleaseNotFoundException(sprintf('The registry service could not be reached to resolve %s: %s', $extension->name, $e->getMessage()), 0, $e);
        }

        $listing ??= $this->localListing($extension->id);
        if ($listing === null) {
            throw new ReleaseNotFoundException(sprintf('%s is no longer listed on the registry service.', $extension->name));
        }

        return $this->release($extension, $listing, $version);
    }

    /**
     * Build the release for a listing: the latest release, or the requested published version.
     *
     * @param array<string, mixed> $listing
     *
     * @throws ReleaseNotFoundException
     */
    public function release(ExtensionRef $extension, array $listing, ?string $version = null): Release
    {
        $release = is_array($listing['latest_release'] ?? null) ? $listing['latest_release'] : [];
        $latest  = self::string($release['version'] ?? null) ?? self::string($listing['latest_version'] ?? null);

        if ($version !== null && $version !== $latest) {
            $published = array_filter(array_map(fn ($entry) => is_array($entry) ? self::string($entry['version'] ?? null) : null, (array) ($listing['versions'] ?? [])));
            if (!in_array($version, $published, true)) {
                throw new ReleaseNotFoundException(sprintf('%s has no published release %s on the registry service.', $extension->name, $version));
            }
        }

        $version ??= $latest;
        if ($version === null) {
            throw new ReleaseNotFoundException(sprintf('%s has no published release on the registry service.', $extension->name));
        }

        $packages   = ServiceCatalog::packages($listing);
        $auth       = new PackageAuth($this->config);
        $repository = $packages['composer'] !== null ? $auth->composerRepository() : null;

        return new Release(
            extension: $extension,
            version: $version,
            composerPackage: $packages['composer'],
            composerConstraint: $packages['composer'] !== null ? $version : null,
            enginePackage: $packages['npm'],
            engineConstraint: $packages['npm'] !== null ? $version : null,
            artifacts: [],
            source: ExtensionRef::REGISTRY_SERVICE,
            engineTarball: $packages['npm'] !== null ? $auth->npmTarballUrl($packages['npm'], $version) : null,
            composerRepository: $repository !== null ? ['name' => PackageAuth::COMPOSER_REPOSITORY_NAME, ...$repository] : null,
        );
    }

    /**
     * @param array<string, mixed> $listing
     */
    private function reference(array $listing): ExtensionRef
    {
        $id = self::string($listing['id'] ?? null) ?? '';

        return new ExtensionRef($id, $id, self::string($listing['name'] ?? null) ?? self::string($listing['slug'] ?? null) ?? $id, ExtensionRef::REGISTRY_SERVICE);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function localListing(string $extension): ?array
    {
        return $this->installedListing ? ($this->installedListing)($extension) : null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
