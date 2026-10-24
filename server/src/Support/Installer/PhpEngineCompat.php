<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

use Fleetbase\RegistryBridge\Support\Installer\Contracts\EngineCompatChecker;

/**
 * A port of `checkEngineCompat` from @fleetbase/ember-core lib/engine-compat.js
 * for API images without Node (the plan moves node and pnpm out of the API
 * image). Same inputs, same result, same policies: the release's
 * `requires.modules` minus the modules the host defines, plus the pinned
 * ember-ui / ember-core version check.
 */
class PhpEngineCompat implements EngineCompatChecker
{
    public const PINNED_PACKAGES = ['@fleetbase/ember-ui', '@fleetbase/ember-core'];

    public function check(string $manifestPath, string $hostModulesPath, string $policy = 'minor'): CompatResult
    {
        $manifest = Files::readJson($manifestPath);
        $host     = Files::readJson($hostModulesPath);
        if ($manifest === null) {
            throw new \RuntimeException(sprintf('Could not read the release manifest %s.', $manifestPath));
        }
        if ($host === null) {
            throw new \RuntimeException(sprintf('Could not read %s; the console build must write host-modules.json (fleetbase#730).', $hostModulesPath));
        }

        return static::compare($manifest, $host, $policy);
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $host
     */
    public static function compare(array $manifest, array $host, string $policy = 'minor'): CompatResult
    {
        $modules = array_flip($host['modules'] ?? []);
        $missing = array_values(array_filter($manifest['requires']['modules'] ?? [], fn ($name) => !isset($modules[$name]) && !isset($modules[$name . '/index'])));

        $hostVersions = $host['versions'] ?? [];
        $versions     = [];
        foreach ($manifest['builtAgainst'] ?? [] as $package => $built) {
            $versions[] = static::compareVersion((string) $package, $built, $hostVersions[$package] ?? null, $policy);
        }

        $reasons = [];
        if ($missing !== []) {
            $reasons[] = sprintf('the console does not define %d module(s) the engine needs', count($missing));
        }
        foreach ($versions as $version) {
            if (!$version['ok']) {
                $reasons[] = sprintf('%s %s (built against) is not compatible with %s (console)', $version['package'], $version['built'], $version['host']);
            }
        }

        return new CompatResult($reasons === [], $missing, $versions, $reasons);
    }

    /**
     * @return array{package: string, built: ?string, host: ?string, ok: bool, note: ?string}
     */
    public static function compareVersion(string $package, mixed $built, mixed $host, string $policy): array
    {
        $result = ['package' => $package, 'built' => $built === null ? null : (string) $built, 'host' => $host === null ? null : (string) $host, 'ok' => true, 'note' => null];
        $b      = self::parseVersion($built);
        $h      = self::parseVersion($host);

        if ($b === null || $h === null) {
            $result['note'] = 'unknown version, not compared';

            return $result;
        }

        $pinned = in_array($package, self::PINNED_PACKAGES, true);
        if ($pinned && $policy === 'exact') {
            $result['ok'] = $result['built'] === $result['host'];
        } elseif ($pinned && $policy === 'minor') {
            $result['ok'] = $b[0] === $h[0] && $b[1] === $h[1];
        } else {
            $result['ok'] = $b[0] === $h[0];
        }

        if ($result['ok'] && $result['built'] !== $result['host']) {
            $result['note'] = 'differs, allowed by policy';
        }

        return $result;
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function parseVersion(mixed $version): ?array
    {
        return preg_match('/^(\d+)\.(\d+)\.(\d+)/', (string) ($version ?? ''), $match) ? [(int) $match[1], (int) $match[2], (int) $match[3]] : null;
    }
}
