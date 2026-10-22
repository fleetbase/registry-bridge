<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * Package names end up in paths and command lines, so they are validated
 * before use.
 */
class PackageName
{
    public static function isNpm(string $name): bool
    {
        return strlen($name) <= 214 && preg_match('/^(@[a-z0-9][a-z0-9._~-]*\/)?[a-z0-9][a-z0-9._~-]*$/', $name) === 1;
    }

    public static function isComposer(string $name): bool
    {
        return preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/', $name) === 1;
    }

    public static function assertNpm(string $name): string
    {
        if (!static::isNpm($name)) {
            throw new InstallerException(sprintf('"%s" is not a valid npm package name.', $name));
        }

        return $name;
    }

    public static function assertComposer(string $name): string
    {
        if (!static::isComposer($name)) {
            throw new InstallerException(sprintf('"%s" is not a valid Composer package name.', $name));
        }

        return $name;
    }

    /**
     * Version constraints go to composer and pnpm as part of one argument; keep
     * them to characters a constraint can contain.
     */
    public static function assertConstraint(?string $constraint): ?string
    {
        if ($constraint !== null && preg_match('/^[A-Za-z0-9 .,*^~<>=|@+_-]*$/', $constraint) !== 1) {
            throw new InstallerException(sprintf('"%s" is not a valid version constraint.', $constraint));
        }

        return $constraint;
    }
}
