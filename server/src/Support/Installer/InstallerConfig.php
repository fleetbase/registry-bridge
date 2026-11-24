<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * Paths, binaries and limits for the installer, built once from
 * `registry-bridge.installer` (see config/registry-bridge.php) so the
 * installer classes never read Laravel config themselves.
 */
class InstallerConfig
{
    /**
     * Run after an install, uninstall or rollback. Never `optimize:clear` or `cache:clear`: on a Redis
     * cache store they FLUSHDB, which also deletes the installer's own job records (the console then
     * reports the job as not found), queued jobs and sessions that share the database, and anything
     * else on that Redis database whatever its key prefix.
     */
    public const DEFAULT_CACHE_COMMANDS = [['config:clear'], ['route:clear'], ['view:clear'], ['event:clear'], ['queue:restart']];

    /**
     * Added to every Composer command. No security audit (a Packagist API round trip on every install; run
     * `composer audit` separately), no progress bars or colour codes in the job log.
     */
    public const DEFAULT_COMPOSER_FLAGS = ['--no-interaction', '--no-progress', '--no-audit', '--no-ansi'];

    /**
     * @param array<int, array<int, string>> $cacheCommands artisan commands run after an install, without `php artisan`
     * @param array<int, string>             $composerFlags options added to every Composer command
     * @param bool                           $batchArtisan  run package discovery, the cache commands and the Octane reload in one
     *                                                      artisan process (`registry:installer-refresh`) instead of one each
     */
    public function __construct(
        public readonly string $apiPath,
        public readonly string $consolePath,
        public readonly string $runtimeEnginesPath,
        public readonly string $workPath,
        public readonly string $runtimeEnginesBaseUrl = '/engines-dist/',
        public readonly ?string $runtimeManifestPath = null,
        public readonly ?string $hostModulesPath = null,
        public readonly string $compatPolicy = 'minor',
        public readonly string $composerBinary = 'composer',
        public readonly string $pnpmBinary = 'pnpm',
        public readonly string $phpBinary = 'php',
        public readonly string $buildEnvironment = 'production',
        public readonly array $cacheCommands = self::DEFAULT_CACHE_COMMANDS,
        public readonly bool $requireChecksums = false,
        public readonly int $processTimeout = 7200,
        public readonly int $logLines = 50,
        public readonly ?string $extensionsPath = null,
        public readonly bool $serveRuntimeEngines = false,
        public readonly bool $reloadOctane = false,
        public readonly array $composerFlags = self::DEFAULT_COMPOSER_FLAGS,
        public readonly bool $batchArtisan = true,
    ) {
    }

    /**
     * The extensions manifest folder: the API's `path.extensions` (fleetbase/fleetbase api/extensions), else
     * <api>/extensions. Installs change the Composer project there when it exists, and the API's own
     * composer.json only on a core from before the manifest.
     */
    public function extensionsDirectory(): string
    {
        return rtrim($this->extensionsPath ?? rtrim($this->apiPath, '/') . '/extensions', '/');
    }

    /**
     * The runtime manifest served at GET ~registry/v1/engines/runtime.
     */
    public function runtimeManifestFile(): string
    {
        return $this->runtimeManifestPath ?? rtrim($this->runtimeEnginesPath, '/') . '/runtime-engines.json';
    }

    /**
     * The console build's module list and pinned versions (written by the
     * console's production build since fleetbase#730).
     */
    /** How long a host-modules.json fetched from a URL is used before it is fetched again, in seconds. */
    public const HOST_MODULES_TTL = 300;

    /**
     * The console's host-modules.json: the configured path, a copy of the configured URL (for an API that cannot
     * see the console's files, such as in Docker), else <console>/dist/host-modules.json. A URL that cannot be
     * fetched leaves the last copy in place; with none, the returned file does not exist.
     */
    public function hostModulesFile(): string
    {
        $configured = $this->hostModulesPath;
        if ($configured === null || $configured === '') {
            return rtrim($this->consolePath, '/') . '/dist/host-modules.json';
        }
        if (!preg_match('#^https?://#i', $configured)) {
            return $configured;
        }

        $copy = rtrim($this->workPath, '/') . '/host-modules-' . substr(hash('sha256', $configured), 0, 12) . '.json';
        if (!is_file($copy) || filemtime($copy) < time() - self::HOST_MODULES_TTL) {
            $body = @file_get_contents($configured, false, stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => false]]));
            if (is_string($body) && is_array(json_decode($body, true))) {
                Files::write($copy, $body);
            }
        }

        return $copy;
    }

    public function engineDirectory(string $package): string
    {
        return rtrim($this->runtimeEnginesPath, '/') . '/' . PackageName::assertNpm($package);
    }

    public function jobDirectory(string $jobId): string
    {
        return rtrim($this->workPath, '/') . '/' . preg_replace('/[^A-Za-z0-9_-]/', '', $jobId);
    }

    /**
     * @param array<string, mixed> $config the `registry-bridge.installer` array
     */
    public static function fromArray(array $config): self
    {
        $consolePath = (string) ($config['console_path'] ?? '/fleetbase/console');
        $baseUrl     = $config['runtime_engines']['base_url'] ?? null;
        // Without a base URL the API serves the runtime engines itself (~registry/v1/engines/files), so they live in its
        // own storage: in Docker the API container has no console to unpack into, and storage/app is a volume.
        $serve       = !is_string($baseUrl) || trim($baseUrl) === '';
        $defaultPath = $serve && function_exists('app') && app() instanceof \Illuminate\Contracts\Foundation\Application
            ? app()->storagePath('app/registry-runtime-engines')
            : rtrim($consolePath, '/') . '/dist/engines-dist';

        return new self(
            apiPath: (string) ($config['api_path'] ?? getcwd()),
            consolePath: $consolePath,
            runtimeEnginesPath: (string) ($config['runtime_engines']['path'] ?? $defaultPath),
            workPath: (string) ($config['work_path'] ?? sys_get_temp_dir() . '/registry-installer'),
            runtimeEnginesBaseUrl: $serve ? '/engines-dist/' : (string) $baseUrl,
            runtimeManifestPath: $config['runtime_engines']['manifest'] ?? null,
            hostModulesPath: $config['compat']['host_modules'] ?? null,
            compatPolicy: (string) ($config['compat']['policy'] ?? 'minor'),
            composerBinary: (string) ($config['binaries']['composer'] ?? 'composer'),
            pnpmBinary: (string) ($config['binaries']['pnpm'] ?? 'pnpm'),
            phpBinary: (string) ($config['binaries']['php'] ?? 'php'),
            buildEnvironment: (string) ($config['build_environment'] ?? 'production'),
            cacheCommands: array_values(array_map(fn ($command) => array_values((array) $command), $config['cache_commands'] ?? self::DEFAULT_CACHE_COMMANDS)),
            requireChecksums: (bool) ($config['require_checksums'] ?? false),
            processTimeout: (int) ($config['timeout'] ?? 7200),
            logLines: (int) ($config['log_lines'] ?? 50),
            extensionsPath: is_string($config['extensions_path'] ?? null) && $config['extensions_path'] !== '' ? $config['extensions_path'] : null,
            serveRuntimeEngines: $serve,
            reloadOctane: (bool) ($config['reload_octane'] ?? false),
            composerFlags: array_values(array_map('strval', (array) ($config['composer_flags'] ?? self::DEFAULT_COMPOSER_FLAGS))),
            batchArtisan: (bool) ($config['batch_artisan'] ?? true),
        );
    }
}
