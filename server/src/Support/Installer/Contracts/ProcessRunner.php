<?php

namespace Fleetbase\RegistryBridge\Support\Installer\Contracts;

use Fleetbase\RegistryBridge\Support\Installer\ProcessResult;

/**
 * Runs the external commands an install needs (composer, pnpm, node, php
 * artisan). Tests swap in a fake that records commands and scripts results.
 */
interface ProcessRunner
{
    /**
     * Run a command and wait for it.
     *
     * @param array<int, string>                $command  the program and its arguments
     * @param string                            $cwd      working directory
     * @param callable(string $line): void|null $onOutput called with every non-empty output line
     * @param int|null                          $timeout  seconds, null for no limit
     */
    public function run(array $command, string $cwd, ?callable $onOutput = null, ?int $timeout = null): ProcessResult;
}
