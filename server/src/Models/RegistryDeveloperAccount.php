<?php

namespace Fleetbase\RegistryBridge\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasUuid;
use Illuminate\Support\Str;

class RegistryDeveloperAccount extends Model
{
    use HasUuid;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'registry_developer_accounts';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'username',
        'email',
        'password',
        'name',
        'avatar_url',
        'github_username',
        'website',
        'bio',
        'email_verified_at',
        'verification_token',
        'status',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'verification_token',
    ];

    /**
     * The "booting" method of the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->verification_token)) {
                $model->verification_token = Str::random(64);
            }
        });
    }

    /**
     * Get the registry users associated with this developer account.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function registryUsers()
    {
        return $this->hasMany(RegistryUser::class, 'developer_account_uuid', 'uuid');
    }

    /**
     * Get the extensions published by this developer account.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function extensions()
    {
        return $this->hasMany(RegistryExtension::class, 'publisher_uuid', 'uuid')
                    ->where('publisher_type', 'developer');
    }

    /**
     * Check if the account is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if the account is suspended.
     *
     * @return bool
     */
    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * Check if the account is pending verification.
     *
     * @return bool
     */
    public function isPendingVerification(): bool
    {
        return $this->status === 'pending_verification';
    }

    /**
     * Check if the email is verified.
     *
     * @return bool
     */
    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Mark the email as verified.
     *
     * @return bool
     */
    public function markEmailAsVerified(): bool
    {
        return $this->update([
            'email_verified_at' => now(),
            'status' => 'active',
            'verification_token' => null,
        ]);
    }

    /**
     * Generate a new verification token.
     *
     * @return string
     */
    public function generateVerificationToken(): string
    {
        $token = Str::random(64);
        $this->update(['verification_token' => $token]);
        return $token;
    }
}
