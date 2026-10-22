<?php

namespace Fleetbase\RegistryBridge\Support;

use Fleetbase\RegistryBridge\Exceptions\InstallFailedException;
use Fleetbase\RegistryBridge\Exceptions\RegistryException;
use Fleetbase\RegistryBridge\Exceptions\RegistryServiceException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Installs and uninstalls extensions served by the Registry Service.
 *
 * Install: report `installing` to the instance API (which checks the licence and hosting and claims a seat),
 * run `composer require` and `pnpm add` against the service's package endpoints with the instance token injected
 * per process (see `PackageAuth`), rebuild the console, then report `installed`, or `failed` with the error.
 * In preinstalled mode the package steps are skipped and only the progress events and reports run.
 *
 * Uninstall: `composer remove`, `pnpm remove`, rebuild, then report the uninstall so the licence seat is released.
 *
 * Processes go through a runner callable so the command plan can be tested without Composer or pnpm.
 */
class ServiceInstaller
{
    public const STEP_SERVER = 'server.install';

    public const STEP_ENGINE = 'engine.install';

    public const STEP_BUILD = 'console.build';

    protected PackageAuth $auth;

    /** @var callable(array{step: string, process: string, command: array<int, string>, cwd: string, env: array<string, string>}): void */
    protected $runner;

    /** @var callable(string, string, int): void */
    protected $progress;

    /**
     * @param (callable(array{step: string, process: string, command: array<int, string>, cwd: string, env: array<string, string>}): void)|null $runner   runs one planned command; throws on failure
     * @param (callable(string, string, int): void)|null                                                                                        $progress receives (process, step, percent) for steps that run without a process
     */
    public function __construct(protected RegistryClient $client, protected RegistryConfig $config, ?callable $runner = null, ?callable $progress = null)
    {
        $this->auth     = new PackageAuth($config);
        $this->runner   = $runner ?? function (array $command) {
            throw new InstallFailedException('No process runner configured for ' . implode(' ', $command['command']));
        };
        $this->progress = $progress ?? function (string $process, string $step, int $percent) {
        };
    }

    /**
     * Install a listing's latest release.
     *
     * @param array<string, mixed> $listing service listing (summary or full)
     * @param array<string, mixed> $context `api_path`, `console_path`, `environment`, `preinstalled`, `installed_by`, `composer_auth` (existing COMPOSER_AUTH)
     *
     * @return array{extension: string, version: string, release: string|null, npm: string|null, composer: string|null, activation: array<string, mixed>|null, reported: bool}
     *
     * @throws InstallFailedException|RegistryServiceException
     */
    public function install(array $listing, array $context = []): array
    {
        $extension = static::extensionKey($listing);
        $release   = static::latestRelease($listing);
        $version   = static::str($release['version'] ?? '') ?: static::str($listing['latest_version'] ?? '');
        $packages  = ServiceCatalog::packages($listing);

        if ($extension === '' || $version === '') {
            throw new InstallFailedException('This extension has no published release to install.');
        }

        if (!$this->config->isRegistered()) {
            throw new InstallFailedException('Register this instance with the registry service before installing extensions.');
        }

        // Licence, hosting and seat checks happen here; a refusal stops the install before anything runs
        try {
            $this->client->reportInstall([
                'extension'    => $extension,
                'version'      => $version,
                'status'       => 'installing',
                'installed_by' => $context['installed_by'] ?? null,
            ]);
        } catch (RegistryServiceException $e) {
            throw new InstallFailedException($e->getMessage(), $e->status(), $e);
        } catch (RegistryException $e) {
            throw new InstallFailedException('The registry service could not be reached to start the install: ' . $e->getMessage(), 0, $e);
        }

        try {
            if ($this->preinstalled($context)) {
                $this->emitSteps('install', [static::STEP_SERVER, static::STEP_ENGINE, static::STEP_BUILD]);
            } else {
                foreach ($this->installPlan($packages, $version, $context) as $command) {
                    ($this->runner)($command);
                }
            }
        } catch (\Throwable $e) {
            $this->report([
                'extension'    => $extension,
                'version'      => $version,
                'status'       => 'failed',
                'error'        => static::truncate($e->getMessage()),
                'installed_by' => $context['installed_by'] ?? null,
            ]);

            throw $e instanceof InstallFailedException ? $e : new InstallFailedException($e->getMessage(), 0, $e);
        }

        $reported = $this->report([
            'extension'    => $extension,
            'version'      => $version,
            'status'       => 'installed',
            'installed_by' => $context['installed_by'] ?? null,
        ]);

        return [
            'extension'  => $extension,
            'version'    => $version,
            'release'    => static::str($release['id'] ?? '') ?: null,
            'npm'        => $packages['npm'],
            'composer'   => $packages['composer'],
            'activation' => is_array($reported['activation'] ?? null) ? $reported['activation'] : null,
            'reported'   => $reported !== null,
        ];
    }

    /**
     * Uninstall a listing's packages and report it to the service.
     *
     * @param array<string, mixed> $listing service listing, or a local install record shaped like one (`id`, `slug`, `packages`)
     * @param array<string, mixed> $context see `install()`, plus `report` (false skips the uninstall report)
     *
     * @return array{extension: string, deactivated: int, reported: bool}
     *
     * @throws InstallFailedException
     */
    public function uninstall(array $listing, array $context = []): array
    {
        $extension = static::extensionKey($listing);
        $packages  = ServiceCatalog::packages($listing);

        if ($this->preinstalled($context)) {
            $this->emitSteps('uninstall', ['server.uninstall', 'engine.uninstall', static::STEP_BUILD]);
        } else {
            try {
                foreach ($this->uninstallPlan($packages, $context) as $command) {
                    ($this->runner)($command);
                }
            } catch (\Throwable $e) {
                throw $e instanceof InstallFailedException ? $e : new InstallFailedException($e->getMessage(), 0, $e);
            }
        }

        $deactivated = 0;
        $reported    = false;
        if ($extension !== '' && $this->config->isRegistered() && ($context['report'] ?? true) !== false) {
            try {
                $result      = $this->client->reportUninstall($extension);
                $deactivated = $result['deactivated'];
                $reported    = true;
            } catch (RegistryServiceException $e) {
                // 404: the service never saw the install (an earlier report failed); nothing to release
                $reported = $e->status() === 404;
                if (!$reported) {
                    Log::warning('Registry uninstall report failed.', ['extension' => $extension, 'error' => $e->getMessage()]);
                }
            } catch (RegistryException $e) {
                Log::warning('Registry uninstall report failed.', ['extension' => $extension, 'error' => $e->getMessage()]);
            }
        }

        return ['extension' => $extension, 'deactivated' => $deactivated, 'reported' => $reported];
    }

    /**
     * Commands an install runs, in order. Each entry: `step`, `process`, `command` (argv), `cwd`, `env`.
     *
     * @param array{npm: string|null, composer: string|null} $packages
     * @param array<string, mixed>                           $context
     *
     * @return array<int, array{step: string, process: string, command: array<int, string>, cwd: string, env: array<string, string>}>
     */
    public function installPlan(array $packages, string $version, array $context = []): array
    {
        $plan     = [];
        $apiPath  = static::str($context['api_path'] ?? '') ?: '/fleetbase/api';
        $console  = static::str($context['console_path'] ?? '') ?: '/fleetbase/console';
        $composer = $this->auth->composerEnv(isset($context['composer_auth']) ? static::str($context['composer_auth']) : null);

        if ($packages['composer']) {
            $repository = $this->auth->composerRepository();
            if ($repository) {
                $plan[] = [
                    'step'    => static::STEP_SERVER,
                    'process' => 'install',
                    'command' => ['composer', 'config', 'repositories.' . PackageAuth::COMPOSER_REPOSITORY_NAME, (string) json_encode($repository, JSON_UNESCAPED_SLASHES)],
                    'cwd'     => $apiPath,
                    'env'     => [],
                ];
            }

            $plan[] = [
                'step'    => static::STEP_SERVER,
                'process' => 'install',
                'command' => ['composer', 'require', PackageAuth::composerInstallSpec($packages['composer'], $version), '--no-interaction'],
                'cwd'     => $apiPath,
                'env'     => $composer,
            ];
        }

        if ($packages['npm']) {
            $plan[] = [
                'step'    => static::STEP_ENGINE,
                'process' => 'install',
                'command' => ['pnpm', 'add', $this->auth->npmInstallSpec($packages['npm'], $version)],
                'cwd'     => $console,
                'env'     => $this->auth->npmEnv(),
            ];

            $plan[] = $this->buildCommand($console, $context, 'install');
        }

        return $plan;
    }

    /**
     * Commands an uninstall runs, in order.
     *
     * @param array{npm: string|null, composer: string|null} $packages
     * @param array<string, mixed>                           $context
     *
     * @return array<int, array{step: string, process: string, command: array<int, string>, cwd: string, env: array<string, string>}>
     */
    public function uninstallPlan(array $packages, array $context = []): array
    {
        $plan    = [];
        $apiPath = static::str($context['api_path'] ?? '') ?: '/fleetbase/api';
        $console = static::str($context['console_path'] ?? '') ?: '/fleetbase/console';

        if ($packages['composer']) {
            $plan[] = [
                'step'    => 'server.uninstall',
                'process' => 'uninstall',
                'command' => ['composer', 'remove', $packages['composer'], '--no-interaction'],
                'cwd'     => $apiPath,
                // `composer remove` re-resolves the tree, which reads the service repository
                'env'     => $this->auth->composerEnv(isset($context['composer_auth']) ? static::str($context['composer_auth']) : null),
            ];
        }

        if ($packages['npm']) {
            $plan[] = [
                'step'    => 'engine.uninstall',
                'process' => 'uninstall',
                'command' => ['pnpm', 'remove', $packages['npm']],
                'cwd'     => $console,
                'env'     => $this->auth->npmEnv(),
            ];

            $plan[] = $this->buildCommand($console, $context, 'uninstall');
        }

        return $plan;
    }

    /**
     * The key the instance API accepts for an extension: its `ext_` id, else slug, else a package name.
     *
     * @param array<string, mixed> $listing
     */
    public static function extensionKey(array $listing): string
    {
        $packages = ServiceCatalog::packages($listing);

        return static::str($listing['id'] ?? '') ?: (static::str($listing['slug'] ?? '') ?: (string) ($packages['npm'] ?? $packages['composer'] ?? ''));
    }

    /**
     * @param array<string, mixed> $listing
     *
     * @return array<string, mixed>
     */
    protected static function latestRelease(array $listing): array
    {
        $release = Arr::get($listing, 'latest_release');

        return is_array($release) ? $release : [];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array{step: string, process: string, command: array<int, string>, cwd: string, env: array<string, string>}
     */
    protected function buildCommand(string $console, array $context, string $process): array
    {
        return [
            'step'    => static::STEP_BUILD,
            'process' => $process,
            'command' => ['pnpm', 'build', '--environment', static::str($context['environment'] ?? '') ?: 'production'],
            'cwd'     => $console,
            'env'     => [],
        ];
    }

    /**
     * Report to the instance API without failing the caller; returns the response or null.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null
     */
    protected function report(array $payload): ?array
    {
        try {
            return $this->client->reportInstall($payload);
        } catch (RegistryException $e) {
            Log::warning('Registry install report failed.', ['extension' => $payload['extension'] ?? null, 'status' => $payload['status'] ?? null, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Publish start and finish events for each step that runs without a process (preinstalled mode).
     *
     * @param array<int, string> $steps
     */
    protected function emitSteps(string $process, array $steps): void
    {
        foreach ($steps as $step) {
            ($this->progress)($process, $step, 0);
            ($this->progress)($process, $step, 100);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function preinstalled(array $context): bool
    {
        return ($context['preinstalled'] ?? false) === true;
    }

    protected static function truncate(string $message, int $limit = 2000): string
    {
        return strlen($message) > $limit ? substr($message, 0, $limit) . '…' : $message;
    }

    protected static function str(mixed $value): string
    {
        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }
}
