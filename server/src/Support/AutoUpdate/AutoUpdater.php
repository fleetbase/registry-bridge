<?php

namespace Fleetbase\RegistryBridge\Support\AutoUpdate;

use Fleetbase\RegistryBridge\Support\AutoUpdate\Contracts\AutoUpdateInstalls;
use Fleetbase\RegistryBridge\Support\Installer\InstallerException;
use Fleetbase\RegistryBridge\Support\Installer\InstallerJobStore;
use Fleetbase\RegistryBridge\Support\Installer\InstallerService;

/**
 * Queues updates for installed extensions with an automatic update policy (`patch` or `minor`).
 *
 * For each such install it reads the service's `me/updates` row (releases compatible with this
 * instance's console and API versions) and takes `latest_patch` or `latest_minor`. Against an older
 * service without those fields it falls back to `latest` when that is inside the policy. A newer
 * version goes to `InstallerService::request('update', …)`: the same queued installer as the console's
 * Update button, so the job takes the install lock, snapshots and rolls back on failure. With
 * preinstalled extensions (cloud) the installer only records the new version.
 *
 * An attempt is remembered per install. While its job is queued or running nothing new is queued;
 * once it failed, was interrupted or is no longer known, that version is not retried (update by
 * hand, wait for a newer release, or run with `$retryFailed`).
 */
class AutoUpdater
{
    public const QUEUED = 'queued';

    public const COMPLETED = 'completed';

    public const WOULD_UPDATE = 'would-update';

    public const UP_TO_DATE = 'up-to-date';

    public const PENDING = 'pending';

    public const SKIPPED_FAILED = 'skipped-failed';

    public const NOT_REPORTED = 'not-reported';

    public const ERROR = 'error';

    private const FAILED_JOB_STATUSES = ['failed', 'interrupted'];

    /** @var callable(): array<int, array<string, mixed>> */
    private $updates;

    /** @var callable(): string */
    private $clock;

    /**
     * @param callable(): array<int, array<string, mixed>> $updates `data` of the service's `me/updates`
     * @param (callable(): string)|null                    $clock   ISO-8601 now
     * @param bool                                         $sync    run each update in this process (`installer.async` off: no queue worker)
     */
    public function __construct(
        private readonly InstallerService $installer,
        private readonly InstallerJobStore $jobs,
        private readonly AutoUpdateInstalls $installs,
        callable $updates,
        ?callable $clock = null,
        private readonly bool $sync = false,
    ) {
        $this->updates = $updates;
        $this->clock   = $clock ?? fn () => date(DATE_ATOM);
    }

    /**
     * @return array<int, array{company: string, extension: string, name: string, policy: string, installed: ?string, target: ?string, result: string, job: ?string, message: ?string}>
     */
    public function run(bool $dryRun = false, bool $retryFailed = false): array
    {
        $installs = $this->installs->withPolicy();
        if ($installs === []) {
            return [];
        }

        $rows = [];
        foreach (($this->updates)() as $row) {
            $id = is_array($row) ? ($row['extension']['id'] ?? null) : null;
            if (is_string($id) && $id !== '') {
                $rows[$id] = $row;
            }
        }

        return array_map(fn (array $install) => $this->one($install, $rows[$install['extension_id']] ?? null, $dryRun, $retryFailed), $installs);
    }

    /**
     * @param array{company: string, extension_id: string, name: string, version: ?string, policy: string, last: ?array<string, mixed>} $install
     * @param array<string, mixed>|null                                                                                                 $row
     *
     * @return array{company: string, extension: string, name: string, policy: string, installed: ?string, target: ?string, result: string, job: ?string, message: ?string}
     */
    private function one(array $install, ?array $row, bool $dryRun, bool $retryFailed): array
    {
        $result = fn (string $outcome, ?string $target = null, ?string $job = null, ?string $message = null) => [
            'company'   => $install['company'],
            'extension' => $install['extension_id'],
            'name'      => $install['name'],
            'policy'    => $install['policy'],
            'installed' => $install['version'],
            'target'    => $target,
            'result'    => $outcome,
            'job'       => $job,
            'message'   => $message,
        ];

        if ($row === null) {
            return $result(self::NOT_REPORTED, null, null, 'The registry service does not list this install for this instance.');
        }

        $installed = $install['version'] ?? ($row['installed']['version'] ?? null);
        $target    = $this->target($install['policy'], $installed, $row);
        if ($target === null) {
            return $result(self::UP_TO_DATE);
        }

        $last = $install['last'] ?? null;
        if (is_array($last) && ($last['version'] ?? null) === $target) {
            $status = $this->lastStatus($last);
            if ($status === 'queued' || $status === 'running') {
                return $result(self::PENDING, $target, $last['job'] ?? null, 'An automatic update to this version is already queued.');
            }
            if (!$retryFailed && $status !== 'completed') {
                $reason = $last['message'] ?? sprintf('The last automatic update to %s ended %s.', $target, $status ?? 'without a known result');

                return $result(self::SKIPPED_FAILED, $target, $last['job'] ?? null, $reason);
            }
        }

        if ($dryRun) {
            return $result(self::WOULD_UPDATE, $target);
        }

        try {
            $job = $this->installer->request('update', $install['extension_id'], $target, $install['company'], null, $this->sync);
        } catch (InstallerException $e) {
            $this->installs->remember($install['company'], $install['extension_id'], ['version' => $target, 'job' => null, 'status' => 'failed', 'message' => $e->getMessage(), 'at' => ($this->clock)()]);

            return $result(self::ERROR, $target, null, $e->getMessage());
        }

        $status = (string) ($job['status'] ?? 'queued');
        $this->installs->remember($install['company'], $install['extension_id'], [
            'version' => $target,
            'job'     => $job['id'] ?? null,
            'status'  => $status,
            'message' => $job['message'] ?? null,
            'at'      => ($this->clock)(),
        ]);

        if (in_array($status, self::FAILED_JOB_STATUSES, true)) {
            return $result(self::ERROR, $target, $job['id'] ?? null, (string) ($job['message'] ?? 'The update failed.'));
        }

        return $result($status === 'completed' ? self::COMPLETED : self::QUEUED, $target, $job['id'] ?? null, !empty($job['duplicate']) ? 'An update of this extension was already queued.' : null);
    }

    /**
     * The newest version inside the policy that the service says is compatible here, or null.
     *
     * @param array<string, mixed> $row
     */
    private function target(string $policy, ?string $installed, array $row): ?string
    {
        $key = 'latest_' . $policy;
        if (array_key_exists($key, $row)) {
            $version = is_array($row[$key]) ? ($row[$key]['version'] ?? null) : null;

            return is_string($version) && UpdatePolicy::allows($policy, $installed, $version) ? $version : null;
        }

        // A service without latest_patch / latest_minor: only `latest`, when it is inside the policy.
        $latest = is_array($row['latest'] ?? null) ? ($row['latest']['version'] ?? null) : null;

        return is_string($latest) && UpdatePolicy::allows($policy, $installed, $latest) ? $latest : null;
    }

    /**
     * The status of the remembered job, reading the job store while it still has the record.
     *
     * @param array<string, mixed> $last
     */
    private function lastStatus(array $last): ?string
    {
        $job = is_string($last['job'] ?? null) ? $this->jobs->get($last['job']) : null;
        if ($job !== null) {
            return (string) ($job['status'] ?? 'queued');
        }

        // The record expired: a job that completed would have moved the installed version past this target.
        return in_array($last['status'] ?? null, ['queued', 'running'], true) ? 'unknown' : ($last['status'] ?? null);
    }
}
