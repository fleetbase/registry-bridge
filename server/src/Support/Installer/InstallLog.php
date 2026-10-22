<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * The install's combined log: stage markers and every line of process output.
 * Failures report the tail.
 */
class InstallLog
{
    /** @var array<int, string> */
    private array $lines = [];

    public function __construct(private readonly int $limit = 2000)
    {
    }

    public function add(string $line): void
    {
        foreach (preg_split('/\r?\n/', rtrim($line)) ?: [] as $part) {
            $this->lines[] = $part;
        }

        if (count($this->lines) > $this->limit) {
            $this->lines = array_slice($this->lines, -$this->limit);
        }
    }

    /**
     * @return array<int, string>
     */
    public function tail(int $count = 50): array
    {
        return array_values(array_slice($this->lines, -$count));
    }

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        return $this->lines;
    }
}
