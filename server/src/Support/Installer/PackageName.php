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
     * Key of a Composer repository entry (`composer config repositories.<name>`).
     */
    public static function isRepositoryName(string $name): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._-]*$/', $name) === 1;
    }

    /**
     * An engine tarball URL goes to pnpm as `name@<url>`: only http(s) URLs
     * ending in .tgz, without credentials, query or fragment.
     */
    public static function assertTarballUrl(?string $url): ?string
    {
        if ($url !== null && preg_match('#^https?://[A-Za-z0-9.-]+(:[0-9]+)?/[A-Za-z0-9@%._~/+-]*\.tgz$#', $url) !== 1) {
            throw new InstallerException(sprintf('"%s" is not a valid package tarball URL.', $url));
        }

        return $url;
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
