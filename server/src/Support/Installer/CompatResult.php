<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * The answer of ember-core's check-engine-compat (`--json` output).
 */
class CompatResult
{
    /**
     * @param array<int, string>               $missing  host modules the release needs and the console lacks
     * @param array<int, array<string, mixed>> $versions per-package comparison (package, built, host, ok, note)
     * @param array<int, string>               $reasons
     */
    public function __construct(
        public readonly bool $compatible,
        public readonly array $missing = [],
        public readonly array $versions = [],
        public readonly array $reasons = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((bool) ($data['compatible'] ?? false), array_values($data['missing'] ?? []), array_values($data['versions'] ?? []), array_values($data['reasons'] ?? []));
    }

    public function summary(int $maxMissing = 10): string
    {
        $summary = implode('; ', $this->reasons);
        if ($this->missing !== []) {
            $shown = array_slice($this->missing, 0, $maxMissing);
            $summary .= sprintf(' (missing: %s%s)', implode(', ', $shown), count($this->missing) > $maxMissing ? sprintf(' and %d more', count($this->missing) - $maxMissing) : '');
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['compatible' => $this->compatible, 'missing' => $this->missing, 'versions' => $this->versions, 'reasons' => $this->reasons];
    }
}
