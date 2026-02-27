<?php

namespace Fleetbase\RegistryBridge\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Company;
use Fleetbase\Models\Model;
use Fleetbase\Models\User;
use Fleetbase\Traits\Expirable;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;

class RegistryUser extends Model
{
    use HasUuid;
    use HasMetaAttributes;
    use HasPublicId;
    use Expirable;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'registry_users';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'registry_user';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'user_uuid',
        'account_type',
        'developer_account_uuid',
        'token',
        'registry_token',
        'scope',
        'expires_at',
        'last_used_at',
        'name',
        'metadata',
        'revoked',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'meta'         => Json::class,
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['is_admin'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * The "booting" method of the model.
     *
     * This method is called when the model is being booted and is used to define
     * model event hooks. In this model, it is used to automatically generate and
     * assign a unique token to the `token` field when creating a new RegistryUser
     * instance. The token is generated only if it hasn't been set already, ensuring
     * that manually specified tokens are respected.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        // Hook into the 'creating' event to set the token for new models
        static::creating(function ($model) {
            // Set the token only if it's not already set
            if (empty($model->token)) {
                $model->token = self::generateToken();
            }
        });
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function developerAccount()
    {
        return $this->belongsTo(RegistryDeveloperAccount::class, 'developer_account_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function extensions()
    {
        return $this->hasMany(RegistryExtension::class, 'company_uuid', 'company_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function purchases()
    {
        return $this->hasMany(RegistryExtensionPurchase::class, 'company_uuid', 'company_uuid');
    }

    /**
     * Get the is_admin attribute.
     */
    public function getIsAdminAttribute(): bool
    {
        if ($this->account_type === 'developer') {
            return false;
        }

        return $this->user && $this->user->is_admin === true;
    }

    /**
     * Determine if the user is an admin.
     *
     * This method checks if the `is_admin` attribute of the user is set to true.
     *
     * @return bool true if the user is an admin, false otherwise
     */
    public function isAdmin(): bool
    {
        return $this->is_admin === true;
    }

    /**
     * Determine if the user is not an admin.
     *
     * This method checks if the `is_admin` attribute of the user is set to false.
     *
     * @return bool true if the user is not an admin, false otherwise
     */
    public function isNotAdmin(): bool
    {
        return $this->is_admin === false;
    }

    /**
     * Generates a unique token for authenticating with the registry.
     *
     * This method creates a token prefixed with 'flb_' and ensures its uniqueness
     * within the `registry_users` table. The token is generated using secure random
     * bytes, converted to a hexadecimal string.
     *
     * @param int the length of the unique token string
     *
     * @return string the unique, generated token
     */
    public static function generateToken(int $length = 18): string
    {
        do {
            // Generate a random string and prepend with 'flb_'
            $token = 'flb_' . bin2hex(random_bytes($length));

            // Check if the token is unique in the database
            $tokenExists = self::where('token', $token)->exists();
        } while ($tokenExists);

        return $token;
    }

    /**
     * Find a registry user by their username.
     *
     * This method searches for a registry user by email or username,
     * supporting both cloud and developer account types.
     *
     * @param string $username the username to search for
     *
     * @return RegistryUser|null the found registry user, or null if no user is found
     */
    public static function findFromUsername(string $username): ?RegistryUser
    {
        // First try to find a cloud user
        $cloudUser = static::select('registry_users.*')
            ->join('users', function ($join) use ($username) {
                $join->on('users.uuid', '=', 'registry_users.user_uuid')
                     ->on('users.company_uuid', '=', 'registry_users.company_uuid')
                     ->where(function ($query) use ($username) {
                         $query->where('users.email', $username)
                               ->orWhere('users.username', $username);
                     });
            })
            ->where('registry_users.account_type', 'cloud')
            ->first();

        if ($cloudUser) {
            return $cloudUser;
        }

        // If not found, try to find a developer account
        return static::select('registry_users.*')
            ->join('registry_developer_accounts', 'registry_developer_accounts.uuid', '=', 'registry_users.developer_account_uuid')
            ->where('registry_users.account_type', 'developer')
            ->where(function ($query) use ($username) {
                $query->where('registry_developer_accounts.email', $username)
                      ->orWhere('registry_developer_accounts.username', $username);
            })
            ->first();
    }

    public static function findFromToken(string $token): ?RegistryUser
    {
        return static::where('registry_token', $token)->orWhere('token', $token)->first();
    }

    /**
     * Determine if the registry user can access a specific package.
     *
     * This method checks if the package name exists in the list of purchased
     * extensions for the user. It verifies if the package name matches either
     * the `package.json` or `composer.json` name in the metadata of the current bundle
     * of any purchased extension.
     *
     * @param string $packageName the name of the package to check access for
     *
     * @return bool true if the user can access the package, false otherwise
     */
    public function canAccessPackage(string $packageName): bool
    {
        return $this->purchases()->whereHas('extension', function ($query) use ($packageName) {
            $query->whereHas('currentBundle', function ($query) use ($packageName) {
                $query->where('meta->package.json->name', $packageName)->orWhere('meta->composer.json->name', $packageName);
            });
        })->exists();
    }

    /**
     * Retrieves the user's access groups.
     *
     * This method returns an array of groups that the user belongs to, including
     * the default groups (`$all` and `$authenticated`) and the names of the purchased
     * extension groups. The purchased extension groups are obtained by calling the
     * `getPurchasedExtensionGroups` method.
     *
     * @return array an array of the user's access groups, including default and purchased extension groups
     */
    public function groups(): array
    {
        return [$this->public_id];
    }

    /**
     * Check if this is a developer account.
     */
    public function isDeveloperAccount(): bool
    {
        return $this->account_type === 'developer';
    }

    /**
     * Check if this is a cloud account.
     */
    public function isCloudAccount(): bool
    {
        return $this->account_type === 'cloud';
    }

    /**
     * Get permissions for this registry user.
     */
    public function getPermissions(): array
    {
        if ($this->isDeveloperAccount()) {
            return [
                'can_install_free_extensions' => true,
                'can_install_paid_extensions' => false,
                'can_publish_extensions'      => true,
                'can_purchase_extensions'     => false,
                'can_access_console'          => false,
                'can_manage_company'          => false,
            ];
        }

        return [
            'can_install_free_extensions' => true,
            'can_install_paid_extensions' => true,
            'can_publish_extensions'      => true,
            'can_purchase_extensions'     => true,
            'can_access_console'          => true,
            'can_manage_company'          => true,
        ];
    }
}
