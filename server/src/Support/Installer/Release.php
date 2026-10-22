<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * One installable version of an extension: the packages to require and,
 * when the publisher shipped one, the prebuilt engine dist (D3 Outcome A).
 */
class Release
{
    /**
     * @param array<int, Artifact> $artifacts
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
     * `name` or `name@constraint` for pnpm add.
     */
    public function engineRequirement(): ?string
    {
        return $this->enginePackage === null ? null : self::requirement($this->enginePackage, $this->engineConstraint, '@');
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
            'composer'  => $this->composerPackage === null ? null : ['name' => $this->composerPackage, 'constraint' => $this->composerConstraint],
            'engine'    => $this->enginePackage === null ? null : ['name' => $this->enginePackage, 'constraint' => $this->engineConstraint, 'prebuilt' => $this->hasPrebuiltEngine()],
            'artifacts' => array_map(fn (Artifact $artifact) => $artifact->toArray(), $this->artifacts),
        ];
    }

    private static function requirement(string $name, ?string $constraint, string $separator): string
    {
        return ($constraint === null || $constraint === '' || $constraint === 'latest') ? $name : $name . $separator . $constraint;
    }
}
