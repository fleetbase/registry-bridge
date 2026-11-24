<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * The user-facing view of an installer job: a few plain steps ("phases") and one overall percentage, worked
 * out from the technical stages ExtensionInstaller runs.
 *
 *   install, update  prepare → download → install → finish
 *   uninstall        prepare → remove → finish
 *
 * Stages map onto phases (STAGE_PHASES) and carry a rough weight (WEIGHTS, about their share of a typical
 * run), so the percentage moves with the work instead of jumping per stage. The plan of stages is known
 * once the release is resolved; a rebuild discovered later adds the build stage, and the context keeps the
 * percentage from ever going backwards.
 */
class InstallProgress
{
    public const PHASES = [
        'install'   => ['prepare', 'download', 'install', 'finish'],
        'update'    => ['prepare', 'download', 'install', 'finish'],
        'uninstall' => ['prepare', 'remove', 'finish'],
    ];

    /** Rough relative cost of each stage. */
    public const WEIGHTS = [
        'resolve'  => 5,
        'download' => 10,
        'snapshot' => 2,
        'composer' => 30,
        'engine'   => 10,
        'build'    => 150,
        'caches'   => 20,
        'record'   => 3,
    ];

    /** The stages an install or update runs before its release is resolved (no rebuild). */
    public const DEFAULT_INSTALL_PLAN = ['resolve', 'download', 'snapshot', 'composer', 'engine', 'caches', 'record'];

    public const DEFAULT_UNINSTALL_PLAN = ['resolve', 'snapshot', 'composer', 'engine', 'caches', 'record'];

    /**
     * @return array<int, string>
     */
    public static function phases(string $action): array
    {
        return self::PHASES[$action] ?? self::PHASES['install'];
    }

    /**
     * The phase a stage belongs to.
     */
    public static function phase(string $action, ?string $stage): string
    {
        $uninstall = $action === 'uninstall';

        return match ($stage) {
            null, 'resolve', 'queued' => 'prepare',
            'download'                => $uninstall ? 'prepare' : 'download',
            'snapshot'                => $uninstall ? 'prepare' : 'install',
            'composer', 'engine', 'build' => $uninstall ? 'remove' : 'install',
            'caches', 'record', 'complete' => 'finish',
            default                   => $uninstall ? 'remove' : 'install',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function defaultPlan(string $action): array
    {
        return $action === 'uninstall' ? self::DEFAULT_UNINSTALL_PLAN : self::DEFAULT_INSTALL_PLAN;
    }

    /**
     * The stages an install or update of $release runs.
     *
     * @return array<int, string>
     */
    public static function installPlan(Release $release, bool $rebuild = false): array
    {
        return array_values(array_filter([
            'resolve',
            'download',
            'snapshot',
            $release->composerPackage !== null ? 'composer' : null,
            $release->enginePackage !== null ? 'engine' : null,
            $release->enginePackage !== null && $rebuild ? 'build' : null,
            'caches',
            'record',
        ]));
    }

    /**
     * Overall percentage (0-99 while running) for a job that finished $completed and is $stepProgress percent
     * into $stage.
     *
     * @param array<int, string> $plan
     * @param array<int, string> $completed
     */
    public static function percent(array $plan, array $completed, ?string $stage, int $stepProgress): int
    {
        if ($stage !== null && !in_array($stage, $plan, true)) {
            $plan[] = $stage;
        }

        $total = 0;
        $done  = 0.0;
        foreach ($plan as $planned) {
            $weight = self::WEIGHTS[$planned] ?? 5;
            $total += $weight;
            if (in_array($planned, $completed, true)) {
                $done += $weight;
            } elseif ($planned === $stage) {
                $done += $weight * max(0, min(100, $stepProgress)) / 100;
            }
        }

        return $total === 0 ? 0 : (int) min(99, floor(100 * $done / $total));
    }
}
