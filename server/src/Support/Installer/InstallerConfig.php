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
     * @param array<int, array<int, string>> $cacheCommands artisan commands run after an install, without `php artisan`
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
        public readonly array $cacheCommands = [['optimize:clear'], ['queue:restart']],
        public readonly bool $requireChecksums = false,
        public readonly int $processTimeout = 7200,
        public readonly int $logLines = 50,
    ) {
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
    public function hostModulesFile(): string
    {
        return $this->hostModulesPath ?? rtrim($this->consolePath, '/') . '/dist/host-modules.json';
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

        return new self(
            apiPath: (string) ($config['api_path'] ?? getcwd()),
            consolePath: $consolePath,
            runtimeEnginesPath: (string) ($config['runtime_engines']['path'] ?? rtrim($consolePath, '/') . '/dist/engines-dist'),
            workPath: (string) ($config['work_path'] ?? sys_get_temp_dir() . '/registry-installer'),
            runtimeEnginesBaseUrl: (string) ($config['runtime_engines']['base_url'] ?? '/engines-dist/'),
            runtimeManifestPath: $config['runtime_engines']['manifest'] ?? null,
            hostModulesPath: $config['compat']['host_modules'] ?? null,
            compatPolicy: (string) ($config['compat']['policy'] ?? 'minor'),
            composerBinary: (string) ($config['binaries']['composer'] ?? 'composer'),
            pnpmBinary: (string) ($config['binaries']['pnpm'] ?? 'pnpm'),
            phpBinary: (string) ($config['binaries']['php'] ?? 'php'),
            buildEnvironment: (string) ($config['build_environment'] ?? 'production'),
            cacheCommands: array_values(array_map(fn ($command) => array_values((array) $command), $config['cache_commands'] ?? [['optimize:clear'], ['queue:restart']])),
            requireChecksums: (bool) ($config['require_checksums'] ?? false),
            processTimeout: (int) ($config['timeout'] ?? 7200),
            logLines: (int) ($config['log_lines'] ?? 50),
        );
    }
}
