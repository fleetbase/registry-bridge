<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * A request the installer refuses (unknown extension, already installed, no
 * company, a busy lock in sync mode). The message is shown to the user.
 */
class InstallerException extends \RuntimeException
{
}
