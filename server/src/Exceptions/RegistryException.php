<?php

namespace Fleetbase\RegistryBridge\Exceptions;

/**
 * Base for errors talking to an extensions registry: unreachable (`RegistryUnavailableException`)
 * or refused (`RegistryServiceException`).
 */
class RegistryException extends \RuntimeException
{
}
