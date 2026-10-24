<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * A failure inside an install stage. The installer rolls back and reports the
 * stage, the message and the log tail.
 */
class StageFailedException extends \RuntimeException
{
    public function __construct(public readonly string $stage, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
