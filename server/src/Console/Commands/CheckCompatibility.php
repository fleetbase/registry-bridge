<?php

namespace Fleetbase\RegistryBridge\Console\Commands;

use Fleetbase\RegistryBridge\Exceptions\RegistryException;
use Fleetbase\RegistryBridge\Support\RegistryClient;
use Illuminate\Console\Command;

/**
 * Before upgrading Fleetbase: would the extensions installed here work on the target core version?
 *
 * Asks the Registry Service (`GET /v1/instances/me/compatibility`) for each installed extension: the
 * newest release whose compat ranges accept the target console/API version, and whether it is `keep`,
 * `update`, `downgrade` or `blocked`. Exit 0 when nothing is blocked, 1 when something is, 2 when the
 * check could not run.
 *
 *     php artisan registry:compat --console=0.8.1 --api=2.0.2
 *     php artisan registry:compat --console=0.8.1 --host-modules=/tmp/next-console/dist/host-modules.json
 */
class CheckCompatibility extends Command
{
    public const BLOCKED = 1;

    public const UNAVAILABLE = 2;

    protected $signature = 'registry:compat
                            {--console= : Target console version (console/package.json of the release)}
                            {--api= : Target fleetbase/core-api version}
                            {--host=* : Host module version of the target console, as package=version (repeatable)}
                            {--host-modules= : host-modules.json of the target console build; its versions are sent as --host}
                            {--policy= : Dist pin policy for --host: exact, minor (default) or major}
                            {--beta : Include beta releases}
                            {--json : Print the service response as JSON}';

    protected $description = 'Check installed extensions against a target Fleetbase core version before upgrading.';

    public function handle(RegistryClient $client): int
    {
        $console = $this->stringOption('console');
        $api     = $this->stringOption('api');
        if ($console === null && $api === null) {
            $this->error('Pass the target version with --console, --api or both.');

            return self::UNAVAILABLE;
        }

        $host = $this->hostVersions();
        if ($host === null) {
            return self::UNAVAILABLE;
        }

        try {
            $report = $client->compatibility([
                'console' => $console,
                'api'     => $api,
                'host'    => $host,
                'policy'  => $host !== [] ? $this->stringOption('policy') : null,
                'channel' => $this->option('beta') ? 'beta' : null,
            ]);
        } catch (RegistryException $e) {
            $this->error('The compatibility check could not run: ' . $e->getMessage());

            return self::UNAVAILABLE;
        }

        $meta       = $report['meta'];
        $compatible = ($meta['compatible'] ?? false) === true;

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $compatible ? self::SUCCESS : self::BLOCKED;
        }

        $target = trim(implode(', ', array_filter([$console ? 'console ' . $console : null, $api ? 'API ' . $api : null])));
        if ($report['data'] === []) {
            $this->info(sprintf('No registry extensions are installed; nothing blocks %s.', $target));

            return self::SUCCESS;
        }

        $this->table(['Extension', 'Installed', 'Action', 'Compatible release', 'Prebuilt dist'], array_map(fn (array $row) => [
            $row['extension']['name'] ?? $row['extension']['slug'] ?? $row['extension']['id'] ?? '?',
            $row['installed']['version'] ?? '-',
            $row['action'] ?? '?',
            $row['compatible']['version'] ?? (($row['reason'] ?? null) === 'no_access' ? 'no access' : 'none'),
            match (true) {
                !is_array($row['dist'] ?? null)                => '-',
                ($row['dist']['compatible'] ?? false) === true => 'fits',
                default                                        => 'rebuild: ' . implode('; ', (array) ($row['dist']['reasons'] ?? [])),
            },
        ], $report['data']));

        if ($compatible) {
            $updates = (int) ($meta['updates_required'] ?? 0);
            $this->info(sprintf('Every installed extension has a release for %s.%s', $target, $updates > 0 ? sprintf(' %d need an update or downgrade with the upgrade.', $updates) : ''));

            return self::SUCCESS;
        }

        $this->error(sprintf('Blocked: %s ha%s no release for %s.', implode(', ', (array) ($meta['blocking_extensions'] ?? [])), count((array) ($meta['blocking_extensions'] ?? [])) === 1 ? 's' : 've', $target));

        return self::BLOCKED;
    }

    /**
     * @return array<string, string>|null null after printing an error
     */
    private function hostVersions(): ?array
    {
        $host = [];

        $file = $this->stringOption('host-modules');
        if ($file !== null) {
            $json = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
            if (!is_array($json) || !is_array($json['versions'] ?? null)) {
                $this->error(sprintf('Could not read versions from %s.', $file));

                return null;
            }
            foreach (['@fleetbase/ember-ui', '@fleetbase/ember-core'] as $package) {
                if (is_string($json['versions'][$package] ?? null)) {
                    $host[$package] = $json['versions'][$package];
                }
            }
        }

        foreach ((array) $this->option('host') as $pair) {
            $at = is_string($pair) ? strrpos($pair, '=') : false;
            if ($at === false || $at === 0) {
                $this->error(sprintf('--host takes package=version, got "%s".', is_string($pair) ? $pair : ''));

                return null;
            }
            $host[substr($pair, 0, $at)] = substr($pair, $at + 1);
        }

        return $host;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
