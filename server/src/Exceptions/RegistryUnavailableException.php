<?php

namespace Fleetbase\RegistryBridge\Exceptions;

/**
 * Thrown when the central registry cannot be reached and no cached catalog is available to fall back on.
 */
class RegistryUnavailableException extends RegistryException
{
}
