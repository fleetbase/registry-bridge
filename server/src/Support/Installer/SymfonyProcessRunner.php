<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

use Fleetbase\RegistryBridge\Support\Installer\Contracts\ProcessRunner;
use Symfony\Component\Process\Process;

class SymfonyProcessRunner implements ProcessRunner
{
    public function run(array $command, string $cwd, ?callable $onOutput = null, ?int $timeout = null): ProcessResult
    {
        $process = new Process($command, $cwd);
        $process->setTimeout($timeout);

        $output  = '';
        $pending = '';
        $process->run(function ($type, $buffer) use (&$output, &$pending, $onOutput) {
            $output .= $buffer;
            $lines   = explode("\n", $pending . $buffer);
            $pending = array_pop($lines);
            foreach ($lines as $line) {
                if ($onOutput && trim($line) !== '') {
                    $onOutput(rtrim($line, "\r"));
                }
            }
        });

        if ($onOutput && trim($pending) !== '') {
            $onOutput(rtrim($pending, "\r"));
        }

        return new ProcessResult($process->getExitCode() ?? 1, $output);
    }
}
