<?php

namespace Fleetbase\RegistryBridge\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Request class for registry access and publish checks.
 *
 * Validates that, when an identity (email or username) is provided, it belongs to either a
 * cloud user or a registry developer account.
 */
class RegistryAuthRequest extends FleetbaseRequest
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
            return AddRegistryUserRequest::identityExists($value);
        });

        return [
            'identity' => [Rule::requiredIf($this->isNotFilled('package')), 'valid_identity'],
            'package'  => ['nullable'],
        ];
    }
}
