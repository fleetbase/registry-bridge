<?php

namespace Fleetbase\RegistryBridge\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\Models\User;
use Fleetbase\RegistryBridge\Models\RegistryDeveloperAccount;
use Illuminate\Support\Facades\Validator;

/**
 * Request class to handle the addition of new registry users.
 *
 * Validates the request data for adding a new user to the registry.
 * Ensures that an identity (email or username) and password are provided and that the
 * identity belongs to either a cloud user or a registry developer account.
 */
class AddRegistryUserRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        Validator::extend('valid_identity', function ($attribute, $value, $parameters, $validator) {
            return static::identityExists($value);
        });

        return [
            'identity' => ['required', 'valid_identity'],
            'password' => ['required'],
        ];
    }

    /**
     * Determine if an identity (email or username) belongs to a cloud user or a developer account.
     */
    public static function identityExists($identity): bool
    {
        if (!is_string($identity) || $identity === '') {
            return false;
        }

        $isCloudUser = User::where(function ($query) use ($identity) {
            $query->where('email', $identity)->orWhere('username', $identity);
        })->whereNull('deleted_at')->exists();

        if ($isCloudUser) {
            return true;
        }

        return RegistryDeveloperAccount::where(function ($query) use ($identity) {
            $query->where('email', $identity)->orWhere('username', $identity);
        })->exists();
    }
}
