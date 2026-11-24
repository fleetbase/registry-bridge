<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * The API's extensions manifest: a Composer project in `api/extensions` (fleetbase/fleetbase) that lists
 * the installed extensions, beside core's own composer.json. The installer changes only this file and its
 * lock, so a core upgrade never conflicts with an install.
 *
 * The manifest requires `fleetbase/core-platform`, a metapackage generated from the packages core
 * installs (the path repository `core-platform`), so Composer resolves extensions against core's
 * packages instead of installing them again. This writer never removes that require or repository.
 */
class ExtensionsManifest
{
    public const CORE_PLATFORM = 'fleetbase/core-platform';

    /** Name of the path repository that serves fleetbase/core-platform. */
    public const CORE_PLATFORM_REPOSITORY = 'core-platform';

    public function __construct(public readonly string $directory)
    {
    }

    /**
     * The manifest in $directory, or null when the API has none (a core from before the manifest).
     */
    public static function find(string $directory): ?self
    {
        $directory = rtrim($directory, '/');

        return is_file($directory . '/composer.json') ? new self($directory) : null;
    }

    public function file(): string
    {
        return $this->directory . '/composer.json';
    }

    public function lockFile(): string
    {
        return $this->directory . '/composer.lock';
    }

    /**
     * The constraint the manifest requires $package at, or null.
     */
    public function constraint(string $package): ?string
    {
        foreach ($this->requireSection($this->read()) as $name => $constraint) {
            if (strcasecmp((string) $name, $package) === 0) {
                return is_string($constraint) ? $constraint : '*';
            }
        }

        return null;
    }

    public function requires(string $package): bool
    {
        return $this->constraint($package) !== null;
    }

    /**
     * Require $package at $constraint (`*` when the release names none), replacing an existing entry.
     */
    public function require(string $package, ?string $constraint): void
    {
        $this->assertNotCorePlatform($package);
        PackageName::assertComposer($package);
        PackageName::assertConstraint($constraint);

        $json              = $this->read();
        $require           = $this->without($this->requireSection($json), $package);
        $require[$package] = $constraint === null || $constraint === '' ? '*' : $constraint;
        $json['require']   = $this->sorted($require);

        $this->write($json);
    }

    public function remove(string $package): void
    {
        $this->assertNotCorePlatform($package);

        $json            = $this->read();
        $json['require'] = $this->without($this->requireSection($json), $package);

        $this->write($json);
    }

    /**
     * Add or replace the repository $name, as `composer config repositories.<name>` does. Repositories
     * written as a list become named entries first, so a name always addresses one entry.
     *
     * @param array<string, mixed> $repository
     */
    public function setRepository(string $name, array $repository): void
    {
        if (!PackageName::isRepositoryName($name) || $name === self::CORE_PLATFORM_REPOSITORY) {
            throw new InstallerException(sprintf('"%s" is not a repository name the installer may set.', $name));
        }
        unset($repository['name']);

        $json         = $this->read();
        $repositories = [];
        foreach ((array) ($json['repositories'] ?? []) as $key => $entry) {
            $named = is_string($key) ? $key : (is_array($entry) && is_string($entry['name'] ?? null) ? $entry['name'] : 'repository-' . $key);
            if (is_array($entry)) {
                unset($entry['name']);
            }
            $repositories[$named] = $entry;
        }
        $repositories[$name]  = $repository;
        $json['repositories'] = $repositories;

        $this->write($json);
    }

    /** Repository key Composer uses for Packagist; `"packagist.org": false` turns it off. */
    public const PACKAGIST = 'packagist.org';

    /**
     * The package names core already installs: what fleetbase/core-platform provides or replaces.
     *
     * @return array<int, string>
     */
    public function corePackages(): array
    {
        $repository = (array) ($this->read()['repositories'][self::CORE_PLATFORM_REPOSITORY] ?? []);
        $url        = is_string($repository['url'] ?? null) ? $repository['url'] : self::CORE_PLATFORM_REPOSITORY;
        $path       = str_starts_with($url, '/') ? $url : $this->directory . '/' . $url;
        $platform   = Files::readJson(rtrim($path, '/') . '/composer.json') ?? [];
        $names      = array_keys([...(array) ($platform['provide'] ?? []), ...(array) ($platform['replace'] ?? [])]);
        $names      = array_values(array_unique(array_map('strtolower', array_filter($names, fn ($name) => is_string($name) && str_contains($name, '/')))));
        sort($names);

        return $names;
    }

    /**
     * Keep Composer from looking up core's own packages on Packagist (`exclude` on the packagist.org
     * repository), so a resolve only reads the metadata of the extension's own dependencies.
     *
     * Without it a partial update of one extension that requires fleetbase/core-api loads every Packagist
     * release of core-api and, through their requirements, most of the Laravel ecosystem (about 1,300
     * metadata requests and 2,400 packages for LocoNav) only to settle on what core-platform provides.
     * Those names can never come from Packagist anyway: core already has them in its own vendor folder.
     *
     * Leaves a manifest that turned Packagist off alone. Returns how many names are excluded.
     */
    public function excludeCorePackagesFromPackagist(): int
    {
        $names = $this->corePackages();
        $json  = $this->read();
        $repos = $json['repositories'] ?? [];
        if ($names === [] || !is_array($repos) || array_is_list($repos) && $repos !== []) {
            // Repositories written as a list cannot be addressed by name; leave them as they are.
            return 0;
        }

        $packagist = $repos[self::PACKAGIST] ?? ['type' => 'composer', 'url' => 'https://repo.packagist.org'];
        if ($packagist === false || !is_array($packagist)) {
            return 0;
        }
        if (($packagist['exclude'] ?? null) === $names) {
            return count($names);
        }

        $packagist['exclude']        = $names;
        $repos[self::PACKAGIST]      = $packagist;
        $json['repositories']        = $repos;
        $this->write($json);

        return count($names);
    }

    /**
     * Whether the manifest's Composer scripts do nothing but `artisan package:discover`, so the installer can
     * run Composer with `--no-scripts` and discover packages in the same artisan process that clears caches.
     */
    public function scriptsOnlyDiscoverPackages(): bool
    {
        $scripts = $this->read()['scripts'] ?? [];
        if (!is_array($scripts)) {
            return false;
        }
        foreach ($scripts as $event => $commands) {
            foreach ((array) $commands as $command) {
                if ($event !== 'post-autoload-dump' || !is_string($command) || !preg_match('#^@php\s+(\S*/)?artisan\s+package:discover(\s+--ansi)?$#', trim($command))) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Let Composer fetch over plain http in this project (`config.secure-http: false`). Only for a registry on a
     * local or private address (see allowsPlainHttp()); Composer refuses http repositories otherwise.
     */
    public function allowPlainHttp(): void
    {
        $json = $this->read();
        if (($json['config']['secure-http'] ?? true) === false) {
            return;
        }
        $json['config']                = (array) ($json['config'] ?? []);
        $json['config']['secure-http'] = false;
        $this->write($json);
    }

    /**
     * Whether a plain-http repository URL is on a local or private network: loopback, Docker's host alias,
     * .localhost/.local/.test/.internal names, private and link-local IPv4 ranges, or unique-local IPv6. A
     * public http URL is never allowed, so Composer's https rule still protects real installs.
     */
    public static function allowsPlainHttp(string $url): bool
    {
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'http') {
            return false;
        }
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if ($host === '') {
            return false;
        }
        if (in_array($host, ['localhost', 'host.docker.internal', 'gateway.docker.internal'], true) || preg_match('/\.(localhost|local|test|internal)$/', $host)) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return str_starts_with($host, '127.') || !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $host === '::1' || str_starts_with($host, 'fc') || str_starts_with($host, 'fd') || str_starts_with($host, 'fe80:');
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        return Files::readJson($this->file()) ?? throw new \RuntimeException(sprintf('%s is not valid JSON.', $this->file()));
    }

    /**
     * Same layout Composer writes (four spaces, unescaped slashes, final newline), through a temporary file
     * so a crash never leaves half a manifest.
     *
     * @param array<string, mixed> $json
     */
    private function write(array $json): void
    {
        $contents  = json_encode(self::composerObjects($json), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $temporary = $this->file() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        Files::write($temporary, $contents . "\n");

        if (!@rename($temporary, $this->file())) {
            @unlink($temporary);

            throw new \RuntimeException(sprintf('Could not write %s.', $this->file()));
        }
    }

    /**
     * Sections Composer's schema requires to be objects, put back as objects: reading the file decodes an
     * empty `{}` to `[]`, and Composer rejects `"allow-plugins": []` or `"require": []`.
     *
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    public static function composerObjects(array $json): array
    {
        foreach (['require', 'require-dev', 'conflict', 'provide', 'replace', 'suggest', 'autoload', 'autoload-dev', 'scripts', 'config', 'extra'] as $key) {
            if (($json[$key] ?? null) === []) {
                $json[$key] = new \stdClass();
            }
        }
        foreach (['allow-plugins', 'platform'] as $key) {
            if (is_array($json['config'] ?? null) && ($json['config'][$key] ?? null) === []) {
                $json['config'][$key] = new \stdClass();
            }
        }

        return $json;
    }

    private function assertNotCorePlatform(string $package): void
    {
        if (strcasecmp($package, self::CORE_PLATFORM) === 0) {
            throw new InstallerException(sprintf('%s describes the packages core installs; the installer never changes it.', self::CORE_PLATFORM));
        }
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array<array-key, mixed>
     */
    private function requireSection(array $json): array
    {
        return is_array($json['require'] ?? null) ? $json['require'] : [];
    }

    /**
     * @param array<array-key, mixed> $require
     *
     * @return array<array-key, mixed>
     */
    private function without(array $require, string $package): array
    {
        return array_filter($require, fn ($name) => strcasecmp((string) $name, $package) !== 0, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Composer's sort-packages order: platform packages (php, ext-*, lib-*) first, then by name.
     *
     * @param array<array-key, mixed> $require
     *
     * @return array<array-key, mixed>
     */
    private function sorted(array $require): array
    {
        $platform = fn (string $name): int => $name === 'php' || str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-') ? 0 : 1;
        uksort($require, fn ($a, $b) => [$platform((string) $a), strtolower((string) $a)] <=> [$platform((string) $b), strtolower((string) $b)]);

        return $require;
    }
}
