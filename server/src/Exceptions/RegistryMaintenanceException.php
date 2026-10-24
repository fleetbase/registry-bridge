<?php

namespace Fleetbase\RegistryBridge\Exceptions;

/**
 * Refused because the cutover freeze is on (`RegistryMaintenance`): answered as 503 with its message.
 */
class RegistryMaintenanceException extends RegistryException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 503);
    }
}
