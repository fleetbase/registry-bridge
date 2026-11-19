<?php

namespace Fleetbase\RegistryBridge\Support\AutoUpdate;

/**
 * Automatic update policies for an installed extension, the same rules as the Registry Service's
 * `Compat::withinPolicy` (which fills `latest_patch` and `latest_minor` on `me/updates`):
 *
 * - `none`: never updated on its own (the default);
 * - `patch`: same major and minor (`~1.2.3`);
 * - `minor`: caret rules (`^1.2.3`): same major, and same minor while the major is 0, since 0.x
 *   minors may break.
 *
 * Prereleases are only inside a policy for an installed prerelease of the same x.y.z.
 */
class UpdatePolicy
{
    public const NONE = 'none';

    public const PATCH = 'patch';

    public const MINOR = 'minor';

    public const ALL = [self::NONE, self::PATCH, self::MINOR];

    /**
     * Add `auto_update` (the policy) and `auto_update_last` (the last automatic attempt) to installed
     * listings, from local install records keyed by extension id. Listings without a record get `none`.
     *
     * @param array<int, array<string, mixed>>                                      $extensions
     * @param array<string, array{policy: mixed, last?: array<string, mixed>|null}> $installs
     *
     * @return array<int, array<string, mixed>>
     */
    public static function decorate(array $extensions, array $installs): array
    {
        return array_map(function (array $extension) use ($installs) {
            $install = $installs[$extension['id'] ?? ''] ?? null;

            return [
                ...$extension,
                'auto_update'      => static::normalize($install['policy'] ?? null),
                'auto_update_last' => is_array($install['last'] ?? null) ? $install['last'] : null,
            ];
        }, $extensions);
    }

    public static function normalize(mixed $policy): string
    {
        return is_string($policy) && in_array($policy, self::ALL, true) ? $policy : self::NONE;
    }

    public static function allows(string $policy, ?string $installed, ?string $candidate): bool
    {
        $from = static::parse($installed);
        $to   = static::parse($candidate);
        if ($from === null || $to === null || static::compare($to, $from) <= 0) {
            return false;
        }

        if ($to[3] !== '' && [$to[0], $to[1], $to[2]] !== [$from[0], $from[1], $from[2]]) {
            return false;
        }

        return match ($policy) {
            self::PATCH => $to[0] === $from[0] && $to[1] === $from[1],
            self::MINOR => $to[0] === $from[0] && ($from[0] > 0 || $to[1] === $from[1]),
            default     => false,
        };
    }

    /**
     * Whether `$candidate` is a higher version than `$current` (an unparseable current version loses).
     */
    public static function isNewer(?string $candidate, ?string $current): bool
    {
        $to = static::parse($candidate);
        if ($to === null) {
            return false;
        }
        $from = static::parse($current);

        return $from === null || static::compare($to, $from) > 0;
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: string}|null major, minor, patch, prerelease
     */
    private static function parse(?string $version): ?array
    {
        if (!preg_match('/^v?(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/', trim((string) $version), $m)) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3], $m[4] ?? ''];
    }

    /**
     * SemVer precedence of two parsed versions.
     *
     * @param array{0: int, 1: int, 2: int, 3: string} $a
     * @param array{0: int, 1: int, 2: int, 3: string} $b
     */
    private static function compare(array $a, array $b): int
    {
        foreach ([0, 1, 2] as $i) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] <=> $b[$i];
            }
        }
        if ($a[3] === '' || $b[3] === '') {
            return ($a[3] === '') <=> ($b[3] === '');
        }

        $x = explode('.', $a[3]);
        $y = explode('.', $b[3]);
        for ($i = 0; $i < max(count($x), count($y)); $i++) {
            if (!isset($x[$i]) || !isset($y[$i])) {
                return isset($x[$i]) ? 1 : -1;
            }
            $cmp = ctype_digit($x[$i]) && ctype_digit($y[$i]) ? (int) $x[$i] <=> (int) $y[$i] : (ctype_digit($x[$i]) ? -1 : (ctype_digit($y[$i]) ? 1 : strcmp($x[$i], $y[$i]) <=> 0));
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return 0;
    }
}
