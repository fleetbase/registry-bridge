<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * A downloaded artifact does not match the checksum its release names. Never retried or worked around: the
 * download is refused and the job fails.
 */
class ChecksumMismatchException extends \RuntimeException
{
}
