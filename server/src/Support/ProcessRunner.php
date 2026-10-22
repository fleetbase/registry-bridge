<?php

namespace Fleetbase\RegistryBridge\Support;

use Fleetbase\RegistryBridge\Exceptions\InstallFailedException;
use Fleetbase\RegistryBridge\Models\RegistryExtensionBundle;
use Fleetbase\Support\SocketCluster\SocketClusterService;
use Symfony\Component\Process\Process;

/**
 * Runs a command planned by `ServiceInstaller` and publishes its progress on the install socket channel.
 *
 * Planned `env` entries are added to the inherited environment of that one process only.
 */
class ProcessRunner
{
    public const TIMEOUT_SECONDS = 3600 * 2;

    public function __construct(protected string $channel)
    {
    }

    /**
     * @param array{step: string, process: string, command: array<int, string>, cwd: string, env: array<string, string>} $command
     *
     * @throws InstallFailedException
     */
    public function __invoke(array $command): void
    {
        $output  = '';
        $process = new Process($command['command'], $command['cwd'], $command['env'] === [] ? null : $command['env']);
        $process->setTimeout(static::TIMEOUT_SECONDS);

        $this->publish($command['process'], $command['step'], 1);
        $process->run(function ($type, $buffer) use (&$output, $command) {
            $output .= $buffer;
            foreach (explode("\n", $buffer) as $line) {
                if (trim($line) === '') {
                    continue;
                }

                $progress = static::progress($command, $line);
                if ($progress > 0) {
                    $this->publish($command['process'], $command['step'], $progress);
                }
            }
        });

        if (!$process->isSuccessful()) {
            throw new InstallFailedException(static::failureMessage($command, $output));
        }

        $this->publish($command['process'], $command['step'], 100);
    }

    /**
     * Publish one progress event on the channel the extension card listens to.
     */
    public function publish(string $process, string $step, int $progress): void
    {
        SocketClusterService::publish($this->channel, [
            'process'  => $process,
            'step'     => $step,
            'progress' => $progress,
        ]);
    }

    /**
     * @param array{command: array<int, string>} $command
     */
    protected static function progress(array $command, string $line): int
    {
        [$tool, $action] = [$command['command'][0] ?? '', $command['command'][1] ?? ''];

        return match (true) {
            $tool === 'composer' && $action === 'require' => RegistryExtensionBundle::composerInstallOutputProgress($line),
            $tool === 'composer' && $action === 'remove'  => RegistryExtensionBundle::composerUninstallOutputProgress($line),
            $tool === 'pnpm' && $action === 'add'         => RegistryExtensionBundle::pnpmInstallOutputProgress($line),
            $tool === 'pnpm' && $action === 'remove'      => RegistryExtensionBundle::pnpmUninstallOutputProgress($line),
            $tool === 'pnpm' && $action === 'build'       => RegistryExtensionBundle::pnpmBuildOutputProgress($line),
            default                                       => 0,
        };
    }

    /**
     * @param array{command: array<int, string>} $command
     */
    protected static function failureMessage(array $command, string $output): string
    {
        if (($command['command'][0] ?? '') === 'composer') {
            return RegistryExtensionBundle::composerOutputFriendly($output);
        }

        $lines = array_slice(array_values(array_filter(array_map('trim', explode("\n", $output)))), -5);

        return implode(' ', $command['command']) . ' failed' . ($lines !== [] ? ': ' . implode(' ', $lines) : '.');
    }
}
