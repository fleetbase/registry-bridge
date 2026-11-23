<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * The runtime engines installed on this instance, served at
 * GET ~registry/v1/engines/runtime and read by the console at boot
 * (@fleetbase/ember-core utils/runtime-engines.js, fleetbase#730).
 *
 * Each entry is the release manifest's `runtime` block plus `name`,
 * `version`, `baseUrl`, `manifest` and the inlined `requires` module list,
 * which saves the browser fetching manifest.json:
 *
 *     { "engines": [{ "name": "@fleetbase/pallet-engine", "version": "0.0.2",
 *       "baseUrl": "/engines-dist/@fleetbase/pallet-engine/",
 *       "mount": { "as": "pallet", "path": "pallet", "root": false },
 *       "bundle": { "assets": [...], "dependencies": [] }, "config": {...},
 *       "routes": "routes.js", "hostApp": "host-app.js", "translations": "translations.json",
 *       "extension": "extension.js", "manifest": "manifest.json", "requires": [...] }] }
 */
class RuntimeEngineManifest
{
    public const SCHEMA = 1;

    public function __construct(private readonly string $file, private readonly string $baseUrl = '/engines-dist/')
    {
    }

    public function file(): string
    {
        return $this->file;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $engines = (Files::readJson($this->file) ?? [])['engines'] ?? [];

        return array_values(array_filter(is_array($engines) ? $engines : [], fn ($engine) => is_array($engine) && is_string($engine['name'] ?? null)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        foreach ($this->all() as $engine) {
            if ($engine['name'] === $name) {
                return $engine;
            }
        }

        return null;
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    /**
     * Add or replace the entry for a release.
     *
     * @param array<string, mixed> $releaseManifest the release's manifest.json
     *
     * @return array<string, mixed> the entry
     */
    public function put(array $releaseManifest): array
    {
        $entry   = static::entry($releaseManifest, $this->baseUrl);
        $engines = array_filter($this->all(), fn ($engine) => $engine['name'] !== $entry['name']);
        $this->write([...$engines, $entry]);

        return $entry;
    }

    public function remove(string $name): bool
    {
        $engines   = $this->all();
        $remaining = array_filter($engines, fn ($engine) => $engine['name'] !== $name);
        if (count($remaining) === count($engines)) {
            return false;
        }
        $this->write($remaining);

        return true;
    }

    /**
     * The response body for GET ~registry/v1/engines/runtime. With `$filesUrl` (the API serves the engines),
     * each `baseUrl` becomes `<filesUrl>/<name>/<version>/`, so the console loads the files from the API and a
     * new version is a new URL.
     *
     * @return array{engines: array<int, array<string, mixed>>}
     */
    public function toResponse(?string $filesUrl = null): array
    {
        $engines = $this->all();
        if ($filesUrl !== null) {
            $engines = array_map(function (array $engine) use ($filesUrl) {
                $engine['baseUrl'] = static::servedBaseUrl($filesUrl, $engine['name'], (string) ($engine['version'] ?? '0'));

                return $engine;
            }, $engines);
        }

        return ['engines' => $engines];
    }

    public static function servedBaseUrl(string $filesUrl, string $name, string $version): string
    {
        return rtrim($filesUrl, '/') . '/' . $name . '/' . rawurlencode($version) . '/';
    }

    /**
     * The file a served URL path names (`<name>/<version>/<file>`, as `servedBaseUrl()` builds it) inside the
     * engine's folder under `$root`, or null: an engine that is not installed, another version, a path that
     * leaves the folder, or no such file.
     */
    public function servedFile(string $root, string $path): ?string
    {
        $segments  = array_values(array_filter(explode('/', $path), fn ($segment) => $segment !== ''));
        $nameParts = isset($segments[0]) && str_starts_with($segments[0], '@') ? 2 : 1;
        if (count($segments) < $nameParts + 2 || in_array('..', $segments, true) || in_array('.', $segments, true)) {
            return null;
        }

        $name    = implode('/', array_slice($segments, 0, $nameParts));
        $version = rawurldecode($segments[$nameParts]);
        $engine  = PackageName::isNpm($name) ? $this->get($name) : null;
        if ($engine === null || (string) ($engine['version'] ?? '0') !== $version) {
            return null;
        }

        $folder = realpath(rtrim($root, '/') . '/' . $name);
        $file   = $folder === false ? false : realpath($folder . '/' . implode('/', array_slice($segments, $nameParts + 1)));

        return $file !== false && str_starts_with($file, $folder . DIRECTORY_SEPARATOR) && is_file($file) ? $file : null;
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return array<string, mixed>
     */
    public static function entry(array $manifest, string $baseUrl = '/engines-dist/'): array
    {
        $name = PackageName::assertNpm((string) ($manifest['name'] ?? ''));
        $base = rtrim($baseUrl, '/') . '/' . $name . '/';

        $entry = [
            'name'     => $name,
            'version'  => $manifest['version'] ?? null,
            'baseUrl'  => $base,
            ...(array) ($manifest['runtime'] ?? []),
            'manifest' => 'manifest.json',
        ];

        if (is_array($manifest['requires']['modules'] ?? null)) {
            $entry['requires'] = array_values($manifest['requires']['modules']);
        }

        return $entry;
    }

    /**
     * @param array<int, array<string, mixed>> $engines
     */
    private function write(array $engines): void
    {
        $engines = array_values($engines);
        usort($engines, fn ($a, $b) => strcmp($a['name'], $b['name']));
        Files::write($this->file, (string) json_encode(['schema' => self::SCHEMA, 'engines' => $engines], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
