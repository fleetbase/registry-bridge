<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * One installable version of an extension: the packages to require and,
 * when the publisher shipped one, the prebuilt engine dist (D3 Outcome A).
 *
 * Releases from the Registry Service also say where the packages live: the
 * engine's tarball URL (added as `name@<url>`, so its npm scope is never
 * remapped) and the Composer repository to add before `composer require`.
 */
class Release
{
    /**
     * @param array<int, Artifact>                                                  $artifacts
     * @param array{name: string, type: string, url: string, canonical?: bool}|null $composerRepository
     */
    public function __construct(
        public readonly ExtensionRef $extension,
        public readonly string $version,
        public readonly ?string $composerPackage = null,
        public readonly ?string $composerConstraint = null,
        public readonly ?string $enginePackage = null,
        public readonly ?string $engineConstraint = null,
        public readonly array $artifacts = [],
        public readonly string $source = 'local',
        public readonly ?string $engineTarball = null,
        public readonly ?array $composerRepository = null,
    ) {
    }

    public function artifact(string $role): ?Artifact
    {
        foreach ($this->artifacts as $artifact) {
            if ($artifact->role === $role) {
                return $artifact;
            }
        }

        return null;
    }

    public function hasPrebuiltEngine(): bool
    {
        return $this->enginePackage !== null && $this->artifact(Artifact::DIST) !== null;
    }

    /**
     * `name` or `name:constraint` for composer require.
     */
    public function composerRequirement(): ?string
    {
        return $this->composerPackage === null ? null : self::requirement($this->composerPackage, $this->composerConstraint, ':');
    }

    /**
     * `name`, `name@constraint` or `name@<tarball url>` for pnpm add.
     */
    public function engineRequirement(): ?string
    {
        if ($this->enginePackage === null) {
            return null;
        }

        return $this->engineTarball !== null ? $this->enginePackage . '@' . $this->engineTarball : self::requirement($this->enginePackage, $this->engineConstraint, '@');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'extension' => $this->extension->toArray(),
            'version'   => $this->version,
            'source'    => $this->source,
            'composer'  => $this->composerPackage === null ? null : ['name' => $this->composerPackage, 'constraint' => $this->composerConstraint, 'repository' => $this->composerRepository['url'] ?? null],
            'engine'    => $this->enginePackage === null ? null : ['name' => $this->enginePackage, 'constraint' => $this->engineConstraint, 'tarball' => $this->engineTarball, 'prebuilt' => $this->hasPrebuiltEngine()],
            'artifacts' => array_map(fn (Artifact $artifact) => $artifact->toArray(), $this->artifacts),
        ];
    }

    private static function requirement(string $name, ?string $constraint, string $separator): string
    {
        return ($constraint === null || $constraint === '' || $constraint === 'latest') ? $name : $name . $separator . $constraint;
    }
}
