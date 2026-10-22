<?php

namespace Fleetbase\RegistryBridge\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Company;
use Fleetbase\Models\Model;
use Fleetbase\Models\User;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasUuid;

/**
 * An extension installed on this instance from the Registry Service (v2).
 *
 * @property string      $uuid
 * @property string|null $company_uuid
 * @property string      $extension_id     service `ext_` id
 * @property string|null $slug
 * @property string|null $name
 * @property string|null $npm_package
 * @property string|null $composer_package
 * @property string|null $version
 * @property string|null $release_id
 */
class RegistryServiceInstall extends Model
{
    use HasUuid;
    use HasMetaAttributes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'registry_service_installs';

    /**
     * The database connection to use.
     *
     * Registry data belongs to the account and only exists in the live database
     * (sandbox migrations are disabled for this package), so it must never follow
     * the sandbox connection switch made by `Auth::setSandboxSession()`.
     *
     * @var string
     */
    protected $connection = 'mysql';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'uuid',
        'company_uuid',
        'installed_by_uuid',
        'extension_id',
        'slug',
        'name',
        'npm_package',
        'composer_package',
        'version',
        'release_id',
        'service_url',
        'meta',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected $casts = [
        'meta' => Json::class,
    ];

    /**
     * Relations that should not be loaded.
     *
     * @var array
     */
    protected $without = ['company', 'installedBy'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function installedBy()
    {
        return $this->belongsTo(User::class, 'installed_by_uuid', 'uuid');
    }

    /**
     * Shape of a local record that `ServiceCatalog` and `ServiceInstaller` accept in place of a listing.
     *
     * @return array<string, mixed>
     */
    public function toListing(): array
    {
        return [
            'id'             => $this->extension_id,
            'slug'           => $this->slug,
            'name'           => $this->name,
            'status'         => 'unlisted',
            'packages'       => ['npm' => $this->npm_package, 'composer' => $this->composer_package],
            'latest_release' => ['id' => $this->release_id, 'version' => $this->version],
        ];
    }

    /**
     * Shape `ServiceCatalog::installed()` takes for local records.
     *
     * @return array<string, mixed>
     */
    public function toLocalInstall(): array
    {
        return [
            'extension_id'     => $this->extension_id,
            'slug'             => $this->slug,
            'name'             => $this->name,
            'npm_package'      => $this->npm_package,
            'composer_package' => $this->composer_package,
            'version'          => $this->version,
            'release_id'       => $this->release_id,
        ];
    }
}
