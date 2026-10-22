<?php

namespace Fleetbase\RegistryBridge\Tests\Installer\Fakes;

use Fleetbase\RegistryBridge\Support\Installer\Contracts\ProcessRunner;
use Fleetbase\RegistryBridge\Support\Installer\ProcessResult;

/**
 * Records every command and answers from scripted handlers. A handler matches
 * when the command starts with its prefix (`['composer', 'require']`), may
 * change files like the real tool would, and returns a ProcessResult, an exit
 * code, or output lines (exit 0). Unmatched commands succeed silently.
 */
class FakeProcessRunner implements ProcessRunner
{
    /** @var array<int, array{command: array<int, string>, cwd: string, env: array<string, string>}> */
    public array $calls = [];

    /** @var array<int, array{prefix: array<int, string>, handler: callable}> */
    private array $handlers = [];

    public function on(array $prefix, callable|int|array $handler): self
    {
        $this->handlers[] = ['prefix' => $prefix, 'handler' => is_callable($handler) ? $handler : fn () => $handler];

        return $this;
    }

    public function run(array $command, string $cwd, ?callable $onOutput = null, ?int $timeout = null, array $env = []): ProcessResult
    {
        $this->calls[] = ['command' => $command, 'cwd' => $cwd, 'env' => $env];

        foreach (array_reverse($this->handlers) as $entry) {
            if (array_slice($command, 0, count($entry['prefix'])) === $entry['prefix']) {
                $result = ($entry['handler'])($command, $cwd);
                if (is_int($result)) {
                    $result = new ProcessResult($result, '');
                } elseif (is_array($result)) {
                    $result = new ProcessResult(0, implode("\n", $result));
                }
                foreach (explode("\n", $result->output) as $line) {
                    if ($onOutput && trim($line) !== '') {
                        $onOutput($line);
                    }
                }

                return $result;
            }
        }

        return new ProcessResult(0, '');
    }

    /**
     * Commands as strings, for readable assertions.
     *
     * @return array<int, string>
     */
    public function commands(): array
    {
        return array_map(fn ($call) => implode(' ', $call['command']), $this->calls);
    }

    public function ran(string $command): bool
    {
        return in_array($command, $this->commands(), true);
    }
}
