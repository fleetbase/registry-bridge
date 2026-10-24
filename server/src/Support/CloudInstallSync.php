<?php

namespace Fleetbase\RegistryBridge\Support;

use Fleetbase\Models\Company;
use Fleetbase\RegistryBridge\Exceptions\RegistryException;
use Fleetbase\RegistryBridge\Models\RegistryServiceInstall;

/**
 * Cutover step for the cloud console's API: record locally (`registry_service_installs`) what each company had
 * installed before the move, as the service's import holds it (`GET /v1/instances/me/tenant-installs`), so every
 * company's console keeps loading its engines and its Installed page is complete from the first request.
 *
 * Idempotent: a company's existing record for an extension is updated in place, never duplicated. Companies this
 * API does not have are skipped.
 */
class CloudInstallSync
{
    /** @var callable(string): bool */
    protected $companyExists;

    /** @var callable(string, array<string, mixed>, bool): string */
    protected $record;

    /**
     * @param (callable(string): bool)|null                               $companyExists defaults to the companies table
     * @param (callable(string, array<string, mixed>, bool): string)|null $record        writes one record, returns created, updated or unchanged; defaults to the model
     */
    public function __construct(protected RegistryClient $client, protected ?string $serviceUrl = null, ?callable $companyExists = null, ?callable $record = null)
    {
        $this->companyExists = $companyExists ?? fn (string $company) => Company::where('uuid', $company)->exists();
        $this->record        = $record ?? fn (string $company, array $attributes, bool $dryRun) => static::upsert($company, $attributes, $dryRun);
    }

    /**
     * @return array{created: int, updated: int, unchanged: int, skipped: int, companies: int}
     *
     * @throws RegistryException
     */
    public function run(bool $dryRun = false): array
    {
        $counts    = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        $companies = [];
        $known     = [];
        $page      = 1;

        do {
            $result   = $this->client->tenantInstalls($page);
            $lastPage = is_numeric($result['meta']['last_page'] ?? null) ? (int) $result['meta']['last_page'] : 1;

            foreach ($result['data'] as $row) {
                $company   = is_string($row['company_uuid'] ?? null) ? $row['company_uuid'] : null;
                $extension = is_string($row['extension']['id'] ?? null) ? $row['extension']['id'] : null;
                if ($company === null || $extension === null || !($known[$company] ??= ($this->companyExists)($company))) {
                    $counts['skipped']++;

                    continue;
                }

                $companies[$company] = true;
                $outcome             = ($this->record)($company, $this->attributes($row), $dryRun);
                $counts[$outcome]    = ($counts[$outcome] ?? 0) + 1;
            }

            $page++;
        } while ($page <= $lastPage && $result['data'] !== []);

        return [...$counts, 'companies' => count($companies)];
    }

    /**
     * The local record for one row of `tenant-installs`, as the installer writes it.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function attributes(array $row): array
    {
        $string = fn (mixed $value) => is_string($value) && $value !== '' ? $value : null;

        return [
            'extension_id'     => $string($row['extension']['id'] ?? null),
            'slug'             => $string($row['extension']['slug'] ?? null),
            'name'             => $string($row['extension']['name'] ?? null),
            'npm_package'      => $string($row['packages']['npm'] ?? null),
            'composer_package' => $string($row['packages']['composer'] ?? null),
            'version'          => $string($row['release']['version'] ?? null),
            'release_id'       => $string($row['release']['id'] ?? null),
            'service_url'      => $this->serviceUrl,
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function upsert(string $company, array $attributes, bool $dryRun): string
    {
        $install = RegistryServiceInstall::firstOrNew(['company_uuid' => $company, 'extension_id' => $attributes['extension_id']]);
        $install->fill($attributes);
        if (!$install->exists) {
            $install->meta = ['source' => 'cloud-sync'];
        }

        $outcome = match (true) {
            !$install->exists   => 'created',
            $install->isDirty() => 'updated',
            default             => 'unchanged',
        };

        if (!$dryRun && $outcome !== 'unchanged') {
            $install->save();
        }

        return $outcome;
    }
}
