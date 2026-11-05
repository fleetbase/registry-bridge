<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

class Checksum
{
    private const ALGORITHMS = ['sha256', 'sha384', 'sha512'];

    /**
     * Whether a file matches an expected checksum (see Artifact for the forms).
     */
    public static function matches(string $file, string $expected): bool
    {
        [$algorithm, $digest] = static::parse($expected);
        $binary               = hash_file($algorithm, $file, true);
        if ($binary === false) {
            return false;
        }

        return hash_equals(strtolower($digest), bin2hex($binary)) || hash_equals($digest, base64_encode($binary));
    }

    public static function sha256(string $file): string
    {
        return 'sha256-' . hash_file('sha256', $file);
    }

    /**
     * @return array{0: string, 1: string} algorithm and digest
     */
    public static function parse(string $expected): array
    {
        $expected = trim($expected);
        if (preg_match('/^(sha256|sha384|sha512)[-:](.+)$/i', $expected, $match)) {
            return [strtolower($match[1]), $match[2]];
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $expected)) {
            return ['sha256', $expected];
        }

        throw new \InvalidArgumentException(sprintf('Unsupported checksum "%s"; expected one of %s.', $expected, implode(', ', self::ALGORITHMS)));
    }
}
