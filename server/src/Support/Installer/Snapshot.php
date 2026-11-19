<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * Copies of the files and folders an install changes, taken before the first
 * change and restored on failure. The index is written to disk so a job that
 * died mid-install (worker restart) can still be rolled back by the next
 * attempt.
 */
class Snapshot
{
    /**
     * @param array<string, array{type: string, existed: bool, copy: string}> $entries keyed by original path
     * @param array<string, mixed>                                            $flags
     */
    private function __construct(private readonly string $directory, private array $entries = [], private array $flags = [])
    {
    }

    /**
     * @param array<int, string> $paths files or folders
     */
    public static function capture(string $directory, array $paths): self
    {
        Files::delete($directory);
        Files::ensureDirectory($directory);

        $snapshot = new self($directory);
        foreach (array_values(array_unique($paths)) as $index => $path) {
            $copy    = $directory . '/' . $index . '-' . basename($path);
            $existed = file_exists($path);
            if ($existed) {
                Files::copy($path, $copy);
            }
            $snapshot->entries[$path] = ['type' => is_dir($path) ? 'dir' : 'file', 'existed' => $existed, 'copy' => $copy];
        }
        $snapshot->save();

        return $snapshot;
    }

    public static function load(string $directory): ?self
    {
        $index = Files::readJson($directory . '/index.json');

        return $index === null ? null : new self($directory, $index['entries'] ?? [], $index['flags'] ?? []);
    }

    /**
     * Record something rollback needs to know (for example that composer ran).
     */
    public function flag(string $key, mixed $value = true): void
    {
        $this->flags[$key] = $value;
        $this->save();
    }

    public function flagged(string $key): mixed
    {
        return $this->flags[$key] ?? null;
    }

    /**
     * Put every path back as it was. Paths that did not exist are removed.
     *
     * @return array<int, string> the restored paths
     */
    public function restore(): array
    {
        $restored = [];
        foreach ($this->entries as $path => $entry) {
            Files::delete($path);
            if ($entry['existed']) {
                Files::copy($entry['copy'], $path);
            }
            $restored[] = $path;
        }

        return $restored;
    }

    /**
     * @return array<int, string>
     */
    public function paths(): array
    {
        return array_keys($this->entries);
    }

    public function discard(): void
    {
        Files::delete($this->directory);
    }

    private function save(): void
    {
        Files::write($this->directory . '/index.json', (string) json_encode(['entries' => $this->entries, 'flags' => $this->flags], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
