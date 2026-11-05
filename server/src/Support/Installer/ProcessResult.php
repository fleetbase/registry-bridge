<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

class ProcessResult
{
    public function __construct(public readonly int $exitCode, public readonly string $output = '')
    {
    }

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
