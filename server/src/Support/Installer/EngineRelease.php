<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * A prebuilt engine release (from @fleetbase/ember-core's
 * build-engine-release) unpacked from its dist archive and verified.
 *
 * The release folder is what the console serves at `/engines-dist/<name>/`:
 * the built engines-dist folder plus routes.js, host-app.js, extension.js,
 * translations.json and manifest.json.
 */
class EngineRelease
{
    /**
     * @param array<string, mixed> $manifest
     */
    public function __construct(public readonly string $path, public readonly array $manifest)
    {
    }

    public function name(): string
    {
        return (string) ($this->manifest['name'] ?? '');
    }

    public function version(): ?string
    {
        return isset($this->manifest['version']) ? (string) $this->manifest['version'] : null;
    }

    public function manifestPath(): string
    {
        return $this->path . '/manifest.json';
    }

    /**
     * Extract a .zip, .tar.gz or .tar archive and verify it against its
     * manifest: every file listed in `files` must exist with that sha256, and
     * nothing else may be in the release.
     */
    public static function extract(string $archive, string $directory, string $expectedName): self
    {
        Files::delete($directory);
        Files::ensureDirectory($directory);
        self::unpack($archive, $directory);

        $root     = self::findRoot($directory);
        $manifest = Files::readJson($root . '/manifest.json') ?? [];

        if (($manifest['name'] ?? null) !== $expectedName) {
            throw new \RuntimeException(sprintf('The engine dist is for "%s", not "%s".', $manifest['name'] ?? 'unknown', $expectedName));
        }

        $runtime = $manifest['runtime'] ?? null;
        if (!is_array($runtime) || empty($runtime['bundle']['assets']) || !is_string($runtime['routes'] ?? null)) {
            throw new \RuntimeException('The engine dist manifest has no runtime entry (bundle assets and routes).');
        }

        self::verifyFiles($root, $manifest);

        return new self($root, $manifest);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function verifyFiles(string $root, array $manifest): void
    {
        $listed = $manifest['files'] ?? null;
        if (!is_array($listed) || $listed === []) {
            throw new \RuntimeException('The engine dist manifest lists no file hashes.');
        }

        foreach ($listed as $file => $hash) {
            $path = $root . '/' . $file;
            if (!self::safeRelative((string) $file) || !is_file($path)) {
                throw new \RuntimeException(sprintf('The engine dist is missing %s.', $file));
            }
            if (!Checksum::matches($path, (string) $hash)) {
                throw new \RuntimeException(sprintf('Checksum mismatch for %s in the engine dist.', $file));
            }
        }

        $unexpected = array_diff(Files::listFiles($root), array_keys($listed), ['manifest.json']);
        if ($unexpected !== []) {
            throw new \RuntimeException(sprintf('The engine dist contains files its manifest does not list: %s.', implode(', ', array_slice(array_values($unexpected), 0, 5))));
        }
    }

    private static function unpack(string $archive, string $directory): void
    {
        $magic = (string) file_get_contents($archive, false, null, 0, 4);

        if (str_starts_with($magic, "PK\x03\x04")) {
            $zip = new \ZipArchive();
            if ($zip->open($archive) !== true) {
                throw new \RuntimeException('Could not open the engine dist archive.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (!self::safeRelative((string) $zip->getNameIndex($i))) {
                    $zip->close();
                    throw new \RuntimeException('The engine dist archive contains an unsafe path.');
                }
            }
            $zip->extractTo($directory);
            $zip->close();

            return;
        }

        // PharData picks the format from the file extension.
        $tar = $directory . '/../' . basename($directory) . (str_starts_with($magic, "\x1f\x8b") ? '.tar.gz' : '.tar');
        copy($archive, $tar);
        try {
            $phar = new \PharData($tar);
            foreach (new \RecursiveIteratorIterator($phar) as $entry) {
                $relative = substr($entry->getPathname(), strlen('phar://' . realpath($tar)) + 1);
                if (!self::safeRelative($relative)) {
                    throw new \RuntimeException('The engine dist archive contains an unsafe path.');
                }
            }
            $phar->extractTo($directory, null, true);
        } catch (\UnexpectedValueException $e) {
            throw new \RuntimeException('Could not open the engine dist archive: ' . $e->getMessage(), 0, $e);
        } finally {
            @unlink($tar);
        }
    }

    /**
     * The folder holding manifest.json: the archive root, or the shallowest
     * folder below it (archives often wrap the release in `<name>/` or
     * `package/`).
     */
    private static function findRoot(string $directory): string
    {
        $candidates = [];
        $items      = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        $items->setMaxDepth(3);
        foreach ($items as $item) {
            if ($item->getFilename() === 'manifest.json') {
                $candidates[] = $item->getPath();
            }
        }

        usort($candidates, fn ($a, $b) => substr_count($a, '/') <=> substr_count($b, '/'));
        if ($candidates === []) {
            throw new \RuntimeException('The engine dist archive has no manifest.json.');
        }

        return $candidates[0];
    }

    private static function safeRelative(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        return $path !== '' && !str_starts_with($path, '/') && !preg_match('#(^|/)\.\.(/|$)#', $path) && !str_contains($path, "\0");
    }
}
