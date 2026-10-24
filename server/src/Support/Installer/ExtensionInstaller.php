<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

use Fleetbase\RegistryBridge\Support\Installer\Contracts\EngineCompatChecker;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\InstallRecorder;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\InstallReporter;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\PackageCredentials;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\ProcessRunner;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\ProgressPublisher;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\ReleaseSource;

/**
 * Runs one installer job as a sequence of stages, publishing progress on the
 * install channel and rolling back every change when a stage fails.
 *
 * Install and update:
 *
 *   resolve   release.resolve   the release from the ReleaseSource, then the InstallReporter
 *                               (the Registry Service checks the licence and claims a seat here)
 *   download  release.download  artifacts, checksums, and the prebuilt dist unpacked and verified
 *   snapshot  snapshot.create   composer.json/lock, console package.json/pnpm-lock.yaml,
 *                               the runtime manifest and the engine's engines-dist folder
 *   composer  server.install    composer config (the release's repository, if any), composer require
 *   engine    engine.install    prebuilt: compat check against host-modules.json, unpack,
 *                               add to the runtime manifest; otherwise pnpm add
 *   build     console.build     pnpm build (only without a prebuilt dist)
 *   caches    cache.clear       php artisan config/route/view/event:clear, queue:restart (configurable;
 *                               never cache:clear, which flushes a shared Redis database)
 *   record    install.record    the company's install record
 *
 * Uninstall runs resolve, snapshot, composer remove (server.uninstall), the
 * engine removal (engine.uninstall, plus console.build for built engines),
 * caches and record. Packages stay when another company still has the
 * extension installed.
 *
 * Composer and pnpm run with the PackageCredentials environment for their
 * command; nothing writes credentials to disk. The InstallReporter hears
 * about the outcome after the job succeeds or is rolled back.
 *
 * Socket events keep the payload the console already reads
 * (`process`, `step`, `progress` from 0 to 100 per step) and add `job`,
 * `stage` and `status`. A failure event carries `status: failed`, the stage,
 * the message and the last log lines.
 */
class ExtensionInstaller
{
    private readonly InstallReporter $reporter;

    private readonly PackageCredentials $credentials;

    public function __construct(
        private readonly InstallerConfig $config,
        private readonly ReleaseSource $releases,
        private readonly ProcessRunner $runner,
        private readonly ProgressPublisher $publisher,
        private readonly EngineCompatChecker $compat,
        private readonly InstallRecorder $recorder,
        private readonly ArtifactDownloader $downloader,
        ?InstallReporter $reporter = null,
        ?PackageCredentials $credentials = null,
    ) {
        $this->reporter    = $reporter ?? new NullInstallReporter();
        $this->credentials = $credentials ?? new NoPackageCredentials();
    }

    /**
     * Run a job record (`id`, `action`, `extension`, `version`, `company`).
     *
     * @param array<string, mixed>                             $job
     * @param callable(array<string, mixed> $patch): void|null $onProgress called with stage/step/progress updates
     *
     * @return array<string, mixed> the job result: `status` completed or failed, plus details
     */
    public function run(array $job, ?callable $onProgress = null): array
    {
        $ctx = new InstallContext($job, $onProgress);

        try {
            if ($ctx->action === 'uninstall') {
                $this->uninstall($ctx);
            } else {
                $this->install($ctx);
            }
        } catch (\Throwable $e) {
            return $this->fail($ctx, $e);
        }

        return $this->succeed($ctx);
    }

    /**
     * Roll back a job whose worker died before it finished, from the snapshot
     * it left on disk.
     *
     * @param array<string, mixed> $job
     *
     * @return array<string, mixed>
     */
    public function recover(array $job, string $reason = 'The installer stopped before this job finished (worker restart or timeout).'): array
    {
        $ctx           = new InstallContext($job);
        $ctx->stage    = (string) ($job['stage'] ?? 'interrupted');
        $ctx->step     = isset($job['step']) ? (string) $job['step'] : null;
        $ctx->snapshot = Snapshot::load($this->config->jobDirectory($ctx->id) . '/snapshot');

        return $this->fail($ctx, new StageFailedException($ctx->stage, $reason));
    }

    private function install(InstallContext $ctx): void
    {
        $this->stage($ctx, 'resolve', 'release.resolve', function () use ($ctx) {
            $release = $this->releases->resolve($ctx->extension, $ctx->version);
            $this->validate($release);
            $ctx->release            = $release;
            $ctx->result['release']  = $release->toArray();
            $ctx->result['version']  = $release->version;
            if ($ctx->action === 'update') {
                $ctx->result['previous_version'] = $this->recorder->installedVersion($ctx->company, $ctx->extension)
                    ?? ($release->enginePackage ? $this->manifest()->get($release->enginePackage)['version'] ?? null : null);
            }

            $this->reporter->starting($ctx->action, $ctx->extension, $release, $ctx->company, $ctx->user);
            $ctx->reportedStart = true;
        });

        $release = $ctx->release();
        $this->stage($ctx, 'download', 'release.download', function () use ($ctx, $release) {
            $directory = $this->config->jobDirectory($ctx->id);
            foreach ($release->artifacts as $artifact) {
                $ctx->log->add(sprintf('Downloading %s artifact', $artifact->role));
                try {
                    $ctx->downloads[$artifact->role] = $this->downloader->download($artifact, $directory . '/downloads');
                } catch (ChecksumMismatchException $e) {
                    throw $e;
                } catch (\RuntimeException $e) {
                    // The service always has the engine package too, so a dist it cannot serve right now (unreachable,
                    // 404, a licence refusal on a host the token is not sent to) costs a rebuild, not the install.
                    if ($artifact->role !== Artifact::DIST || $release->source !== ExtensionRef::REGISTRY_SERVICE) {
                        throw $e;
                    }
                    $this->skipPrebuilt($ctx, sprintf('The prebuilt dist could not be downloaded: %s', $e->getMessage()));
                }
            }
            if ($release->hasPrebuiltEngine() && isset($ctx->downloads[Artifact::DIST])) {
                $ctx->engineRelease = EngineRelease::extract($ctx->downloads[Artifact::DIST], $directory . '/dist', (string) $release->enginePackage);
                $ctx->log->add(sprintf('Verified %s@%s (%d files)', $ctx->engineRelease->name(), $ctx->engineRelease->version(), count($ctx->engineRelease->manifest['files'] ?? [])));
            }
        });

        $this->stage($ctx, 'snapshot', 'snapshot.create', function () use ($ctx, $release) {
            $ctx->snapshot = Snapshot::capture($this->config->jobDirectory($ctx->id) . '/snapshot', $this->snapshotPaths($release));
        });

        if ($release->composerPackage !== null) {
            $this->stage($ctx, 'composer', 'server.install', function () use ($ctx, $release) {
                $ctx->snapshot()->flag('composer');
                if ($release->composerRepository !== null) {
                    // composer.json is in the snapshot, so a rollback removes the repository again
                    $repository = $release->composerRepository;
                    $name       = $repository['name'];
                    unset($repository['name']);
                    $this->exec($ctx, [$this->config->composerBinary, 'config', 'repositories.' . $name, (string) json_encode($repository, JSON_UNESCAPED_SLASHES)], $this->config->apiPath);
                }
                $this->exec($ctx, [$this->config->composerBinary, 'require', (string) $release->composerRequirement(), '--no-interaction'], $this->config->apiPath, 'server.install', [OutputProgress::class, 'composerInstallOutputProgress']);
            });
        }

        if ($release->enginePackage !== null) {
            $rebuild = $ctx->engineRelease === null;
            $this->stage($ctx, 'engine', 'engine.install', function () use ($ctx, $release, &$rebuild) {
                if (!$rebuild) {
                    $refusal = $this->prebuiltRefusal($ctx, $release);
                    if ($refusal === null) {
                        $this->installPrebuiltEngine($ctx);

                        return;
                    }
                    // A Registry Service release always has its engine package on the service, so a console that
                    // cannot take the prebuilt dist installs that and rebuilds instead (D3: rebuild only as fallback).
                    if ($release->source !== ExtensionRef::REGISTRY_SERVICE) {
                        throw new \RuntimeException($refusal);
                    }
                    $this->skipPrebuilt($ctx, $refusal);
                    $rebuild = true;
                } elseif ($release->source === ExtensionRef::REGISTRY_SERVICE && !isset($ctx->result['prebuilt_skipped'])) {
                    $this->skipPrebuilt($ctx, sprintf('%s %s has no prebuilt dist on the registry service.', $release->enginePackage, $release->version));
                }
                $this->addEnginePackage($ctx, $release);
            });
            if ($rebuild) {
                $this->stage($ctx, 'build', 'console.build', fn () => $this->buildConsole($ctx));
            }
        }

        $this->stage($ctx, 'caches', 'cache.clear', fn () => $this->clearCaches($ctx));

        $this->stage($ctx, 'record', 'install.record', function () use ($ctx, $release) {
            $this->recorder->record($ctx->company, $ctx->extension, $release, [
                'version'      => $release->version,
                'mode'         => $ctx->result['mode'] ?? 'server',
                'source'       => $release->source,
                'job'          => $ctx->id,
                'installed_at' => date(DATE_ATOM),
            ]);
        });
    }

    private function uninstall(InstallContext $ctx): void
    {
        $this->stage($ctx, 'resolve', 'release.resolve', function () use ($ctx) {
            $release = $this->releases->resolve($ctx->extension, null);
            $this->validate($release);
            $ctx->release                  = $release;
            $ctx->result['release']        = $release->toArray();
            $ctx->result['packages_kept']  = $this->recorder->installedElsewhere($ctx->company, $ctx->extension);
        });

        $release = $ctx->release();
        if (!$ctx->result['packages_kept']) {
            $this->stage($ctx, 'snapshot', 'snapshot.create', function () use ($ctx, $release) {
                $ctx->snapshot = Snapshot::capture($this->config->jobDirectory($ctx->id) . '/snapshot', $this->snapshotPaths($release));
            });

            if ($release->composerPackage !== null && $this->composerRequires($release->composerPackage)) {
                $this->stage($ctx, 'composer', 'server.uninstall', function () use ($ctx, $release) {
                    $ctx->snapshot()->flag('composer');
                    $this->exec($ctx, [$this->config->composerBinary, 'remove', (string) $release->composerPackage, '--no-interaction'], $this->config->apiPath, 'server.uninstall', [OutputProgress::class, 'composerUninstallOutputProgress']);
                });
            }

            $engine = $release->enginePackage;
            if ($engine !== null && $this->manifest()->has($engine)) {
                $this->stage($ctx, 'engine', 'engine.uninstall', function () use ($ctx, $engine) {
                    $this->manifest()->remove($engine);
                    Files::delete($this->config->engineDirectory($engine));
                    $ctx->result['mode'] = 'runtime';
                });
            } elseif ($engine !== null && $this->consoleDepends($engine)) {
                $this->stage($ctx, 'engine', 'engine.uninstall', function () use ($ctx, $engine) {
                    $ctx->snapshot()->flag('pnpm');
                    $this->exec($ctx, [$this->config->pnpmBinary, 'remove', $engine], $this->config->consolePath, 'engine.uninstall', [OutputProgress::class, 'pnpmUninstallOutputProgress']);
                    $ctx->result['mode'] = 'build';
                });
                $this->stage($ctx, 'build', 'console.build', fn () => $this->buildConsole($ctx));
            }

            $this->stage($ctx, 'caches', 'cache.clear', fn () => $this->clearCaches($ctx));
        }

        $this->stage($ctx, 'record', 'install.record', fn () => $this->recorder->remove($ctx->company, $ctx->extension));
    }

    /**
     * Why this console cannot take the unpacked prebuilt engine, or null when it can.
     */
    private function prebuiltRefusal(InstallContext $ctx, Release $release): ?string
    {
        $engine = $ctx->engineRelease ?? throw new \LogicException('The prebuilt engine is unpacked in the download stage.');

        // Every Registry Service install before prebuilt dists were served was built into the console; a runtime
        // copy beside the built one would load the engine twice, so those keep updating by rebuild.
        if ($release->source === ExtensionRef::REGISTRY_SERVICE && $this->consoleDepends($engine->name()) && !$this->manifest()->has($engine->name())) {
            return sprintf('%s is built into this console, so it is updated by a rebuild.', $engine->name());
        }

        $hostModules = $this->config->hostModulesFile();
        if (!is_file($hostModules)) {
            return sprintf('The console build has no host-modules.json (looked for %s). Rebuild the console with a version that writes it before installing prebuilt engines.', $hostModules);
        }

        $compat                 = $this->compat->check($engine->manifestPath(), $hostModules, $this->config->compatPolicy);
        $ctx->result['compat']  = $compat->toArray();
        $ctx->log->add(sprintf('Compatibility (%s policy): %s', $this->config->compatPolicy, $compat->compatible ? 'compatible' : $compat->summary()));

        return $compat->compatible ? null : 'The prebuilt engine is not compatible with this console: ' . $compat->summary();
    }

    /**
     * Record why a release installs by rebuild instead of from its prebuilt dist: in the job log, in the result as
     * `prebuilt_skipped`, and in the application log, so an operator can see what cost the rebuild.
     */
    private function skipPrebuilt(InstallContext $ctx, string $reason): void
    {
        $ctx->log->add($reason . ' Installing the engine package and rebuilding the console instead.');
        $ctx->result['prebuilt_skipped'] = $reason;
        if (function_exists('logger')) {
            try {
                logger()->warning('[registry-bridge] Prebuilt dist not used, rebuilding the console', ['job' => $ctx->id, 'reason' => $reason]);
            } catch (\Throwable) {
                // Outside a Laravel application (tests, scripts) the job log is the record.
            }
        }
    }

    /**
     * `pnpm add` the engine package for a console build. A runtime copy of the same engine is removed (the
     * snapshot restores it on failure), so the rebuilt console does not load it twice.
     */
    private function addEnginePackage(InstallContext $ctx, Release $release): void
    {
        $engine = (string) $release->enginePackage;
        if ($this->manifest()->has($engine)) {
            $this->manifest()->remove($engine);
            Files::delete($this->config->engineDirectory($engine));
            $ctx->log->add(sprintf('Removed the runtime copy of %s', $engine));
        }

        $ctx->snapshot()->flag('pnpm');
        // A console whose pnpm-workspace.yaml lists packages (local engine links in development) is a
        // workspace root, and pnpm refuses a root `add` without this (ERR_PNPM_ADDING_TO_ROOT).
        $this->exec($ctx, [$this->config->pnpmBinary, 'add', (string) $release->engineRequirement(), '--config.ignore-workspace-root-check=true'], $this->config->consolePath, 'engine.install', [OutputProgress::class, 'pnpmInstallOutputProgress']);
        $ctx->result['mode'] = 'build';
    }

    private function installPrebuiltEngine(InstallContext $ctx): void
    {
        $engine = $ctx->engineRelease ?? throw new \LogicException('The prebuilt engine is unpacked in the download stage.');
        $this->emit($ctx, 'engine.install', 40);

        $target = $this->config->engineDirectory($engine->name());
        $ctx->snapshot()->flag('unpacked', $target);
        Files::move($engine->path, $target);
        $ctx->log->add(sprintf('Unpacked %s into %s', $engine->name(), $target));
        $this->emit($ctx, 'engine.install', 80);

        $ctx->result['runtime_engine'] = $this->manifest()->put($engine->manifest);
        $ctx->result['mode']           = 'runtime';
    }

    /**
     * pnpm build replaces the console's dist folder. Runtime engines unpacked
     * inside it are moved aside for the build and put back afterwards.
     */
    private function buildConsole(InstallContext $ctx): void
    {
        $dist      = rtrim($this->config->consolePath, '/') . '/dist/';
        $preserve  = str_starts_with(rtrim($this->config->runtimeEnginesPath, '/') . '/', $dist);
        $aside     = $this->config->jobDirectory($ctx->id) . '/preserved';
        $preserved = [];

        if ($preserve) {
            $paths = [$this->config->runtimeManifestFile()];
            foreach ($this->manifest()->all() as $engine) {
                $paths[] = $this->config->engineDirectory($engine['name']);
            }
            foreach ($paths as $index => $path) {
                if (file_exists($path)) {
                    Files::move($path, $aside . '/' . $index);
                    $preserved[$path] = $aside . '/' . $index;
                }
            }
        }

        try {
            $ctx->snapshot?->flag('built');
            // FLEETBASE_HOST_MODULES=1 keeps dist/host-modules.json in every environment: without it a non-production
            // rebuild drops the file and the console refuses every prebuilt engine afterwards.
            $this->exec($ctx, [$this->config->pnpmBinary, 'build', '--environment', $this->config->buildEnvironment], $this->config->consolePath, 'console.build', [OutputProgress::class, 'pnpmBuildOutputProgress'], ['FLEETBASE_HOST_MODULES' => '1']);
        } finally {
            foreach ($preserved as $path => $copy) {
                Files::move($copy, $path);
            }
        }
    }

    private function clearCaches(InstallContext $ctx): void
    {
        foreach ($this->config->cacheCommands as $command) {
            $this->exec($ctx, [$this->config->phpBinary, 'artisan', ...$command], $this->config->apiPath);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function succeed(InstallContext $ctx): array
    {
        $ctx->snapshot?->discard();
        Files::delete($this->config->jobDirectory($ctx->id));

        if ($ctx->release !== null && ($ctx->action === 'uninstall' || $ctx->reportedStart)) {
            $this->report($ctx, fn () => $this->reporter->finished($ctx->action, $ctx->extension, $ctx->release(), $ctx->company, $ctx->user, !($ctx->result['packages_kept'] ?? false)));
        }

        $step = ($ctx->action === 'uninstall' ? 'uninstall' : 'install') . '.complete';
        $this->publish($ctx, ['process' => $ctx->process($step), 'step' => $step, 'progress' => 100, 'status' => 'completed', 'job' => $ctx->id, 'stage' => null]);

        return [...$ctx->result, 'status' => 'completed', 'stages' => $ctx->completed, 'log' => $ctx->log->tail($this->config->logLines)];
    }

    /**
     * @return array<string, mixed>
     */
    private function fail(InstallContext $ctx, \Throwable $e): array
    {
        $stage   = $e instanceof StageFailedException ? $e->stage : ($ctx->stage ?? 'unknown');
        $step    = $ctx->step;
        $message = $e->getMessage();
        $ctx->log->add(sprintf('!! %s failed: %s', $stage, $message));
        // The tail as it was when the stage failed; rollback output would push the error out of it.
        $log = $ctx->log->tail($this->config->logLines);

        $rollback = $this->rollback($ctx);
        Files::delete($this->config->jobDirectory($ctx->id));

        if ($ctx->reportedStart && $ctx->release !== null) {
            $this->report($ctx, fn () => $this->reporter->failed($ctx->action, $ctx->extension, $ctx->release(), $ctx->company, $message, $ctx->user));
        }

        $this->publish($ctx, [
            'process'  => $ctx->process((string) $step),
            'step'     => $step,
            'stage'    => $stage,
            'progress' => 100,
            'status'   => 'failed',
            'job'      => $ctx->id,
            'message'  => $message,
            'log'      => $log,
        ]);

        return [...$ctx->result, 'status' => 'failed', 'failed_stage' => $stage, 'step' => $step, 'message' => $message, 'stages' => $ctx->completed, 'rollback' => $rollback, 'log' => $log];
    }

    /**
     * Restore the snapshot, then bring vendor/ and node_modules back in line
     * with the restored lock files. Rollback errors are reported, never thrown,
     * so the original failure stays the job's error.
     *
     * @return array<string, mixed>
     */
    private function rollback(InstallContext $ctx): array
    {
        $snapshot = $ctx->snapshot;
        if (!$snapshot) {
            return ['performed' => false, 'restored' => [], 'errors' => [], 'console_rebuild_required' => false];
        }

        $ctx->step = 'rollback';
        $this->emit($ctx, 'rollback', 0);
        $errors   = [];
        $restored = [];

        try {
            $restored = $snapshot->restore();
            $ctx->log->add(sprintf('Restored %d snapshot path(s)', count($restored)));
        } catch (\Throwable $e) {
            $errors[] = 'restore: ' . $e->getMessage();
        }

        $commands = [];
        if ($snapshot->flagged('composer')) {
            $commands[] = [[$this->config->composerBinary, 'install', '--no-interaction'], $this->config->apiPath];
        }
        if ($snapshot->flagged('pnpm')) {
            $commands[] = [[$this->config->pnpmBinary, 'install', '--frozen-lockfile'], $this->config->consolePath];
        }
        if ($snapshot->flagged('composer')) {
            foreach ($this->config->cacheCommands as $command) {
                $commands[] = [[$this->config->phpBinary, 'artisan', ...$command], $this->config->apiPath];
            }
        }
        foreach ($commands as [$command, $cwd]) {
            try {
                $this->exec($ctx, $command, $cwd);
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        $this->emit($ctx, 'rollback', 100);
        $snapshot->discard();

        return [
            'performed'                => true,
            'restored'                 => $restored,
            'errors'                   => $errors,
            // A finished pnpm build already replaced the console's dist; the restored lock files only take effect on the next build.
            'console_rebuild_required' => (bool) $snapshot->flagged('built') && in_array('build', $ctx->completed, true),
        ];
    }

    /**
     * @param callable(): void $callback
     */
    private function stage(InstallContext $ctx, string $stage, string $step, callable $callback): void
    {
        $ctx->stage = $stage;
        $ctx->step  = $step;
        $ctx->log->add(sprintf('==> %s (%s)', $stage, $step));
        $this->emit($ctx, $step, 0);

        try {
            $callback();
        } catch (StageFailedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new StageFailedException($stage, $e->getMessage(), $e);
        }

        $ctx->completed[] = $stage;
        $this->emit($ctx, $step, 100);
    }

    /**
     * @param array<int, string>         $command
     * @param callable(string): int|null $progress maps an output line to a percentage
     * @param array<string, string>      $env      extra environment for this command, over the credentials'
     */
    private function exec(InstallContext $ctx, array $command, string $cwd, ?string $step = null, ?callable $progress = null, array $env = []): ProcessResult
    {
        $ctx->log->add('$ ' . implode(' ', $command));
        $last = 0;

        $env    = array_merge($this->credentials->environment($command), $env);
        $result = $this->runner->run($command, $cwd, function (string $line) use ($ctx, $step, $progress, &$last) {
            $ctx->log->add($line);
            if ($step && $progress) {
                $value = min(99, (int) $progress($line));
                if ($value > $last) {
                    $last = $value;
                    $this->emit($ctx, $step, $value);
                }
            }
        }, $this->config->processTimeout, $env);

        if (!$result->successful()) {
            $summary = $command[0] === $this->config->composerBinary ? ' ' . OutputProgress::composerOutputFriendly($result->output) : '';
            throw new \RuntimeException(static::failureMessage($command, $result->exitCode) . $summary);
        }

        return $result;
    }

    /**
     * "`<full command>` exited with code N." with every argument, so a failure names exactly what ran. Exit 137
     * is SIGKILL, which during an install is almost always the kernel's out-of-memory killer (a console build
     * needs several GB).
     *
     * @param array<int, string> $command
     */
    public static function failureMessage(array $command, int $exitCode): string
    {
        $quoted  = implode(' ', array_map(fn ($arg) => preg_match('/^[\w@%+=:,.\/\-^~]+$/', (string) $arg) ? (string) $arg : escapeshellarg((string) $arg), $command));
        $message = sprintf('`%s` exited with code %d.', $quoted, $exitCode);

        if ($exitCode === 137) {
            $message .= ' The process was killed, most likely because the machine ran out of memory; give the installer more memory and try again.';
        }

        return $message;
    }

    /**
     * Run a reporter call that must not change the job's outcome.
     *
     * @param callable(): void $call
     */
    private function report(InstallContext $ctx, callable $call): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            $ctx->log->add('(registry not told about the outcome: ' . $e->getMessage() . ')');
            $ctx->result['report_error'] = $e->getMessage();
        }
    }

    private function emit(InstallContext $ctx, string $step, int $progress): void
    {
        $this->publish($ctx, ['process' => $ctx->process($step), 'step' => $step, 'progress' => $progress, 'status' => 'running', 'job' => $ctx->id, 'stage' => $ctx->stage]);
        $ctx->notify(['stage' => $ctx->stage, 'step' => $step, 'progress' => $progress]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function publish(InstallContext $ctx, array $payload): void
    {
        try {
            $this->publisher->publish($ctx->channel, $payload);
        } catch (\Throwable $e) {
            // Progress is best effort; a socket outage must not fail an install.
            $ctx->log->add('(progress event not delivered: ' . $e->getMessage() . ')');
        }
    }

    private function validate(Release $release): void
    {
        if ($release->composerPackage !== null) {
            PackageName::assertComposer($release->composerPackage);
            PackageName::assertConstraint($release->composerConstraint);
        }
        if ($release->enginePackage !== null) {
            PackageName::assertNpm($release->enginePackage);
            PackageName::assertConstraint($release->engineConstraint);
            PackageName::assertTarballUrl($release->engineTarball);
        }
        if ($release->composerRepository !== null && (!PackageName::isRepositoryName($release->composerRepository['name']) || !preg_match('#^https?://#', $release->composerRepository['url']))) {
            throw new \RuntimeException('The release names an invalid Composer repository.');
        }
        if ($release->composerPackage === null && $release->enginePackage === null) {
            throw new \RuntimeException('The release has neither a Composer nor an engine package.');
        }
    }

    /**
     * @return array<int, string>
     */
    private function snapshotPaths(Release $release): array
    {
        $api     = rtrim($this->config->apiPath, '/');
        $console = rtrim($this->config->consolePath, '/');
        $paths   = [$api . '/composer.json', $api . '/composer.lock', $console . '/package.json', $console . '/pnpm-lock.yaml', $this->config->runtimeManifestFile()];
        if ($release->enginePackage !== null) {
            $paths[] = $this->config->engineDirectory($release->enginePackage);
        }

        return $paths;
    }

    private function composerRequires(string $package): bool
    {
        return isset((Files::readJson(rtrim($this->config->apiPath, '/') . '/composer.json') ?? [])['require'][$package]);
    }

    private function consoleDepends(string $package): bool
    {
        $json = Files::readJson(rtrim($this->config->consolePath, '/') . '/package.json') ?? [];

        return isset($json['dependencies'][$package]) || isset($json['devDependencies'][$package]);
    }

    private function manifest(): RuntimeEngineManifest
    {
        return new RuntimeEngineManifest($this->config->runtimeManifestFile(), $this->config->runtimeEnginesBaseUrl);
    }
}
