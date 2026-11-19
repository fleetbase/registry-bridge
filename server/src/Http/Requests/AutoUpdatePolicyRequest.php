<?php

namespace Fleetbase\RegistryBridge\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\RegistryBridge\Support\AutoUpdate\UpdatePolicy;
use Illuminate\Validation\Rule;

class AutoUpdatePolicyRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return session('company');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'policy' => ['required', 'string', Rule::in(UpdatePolicy::ALL)],
        ];
    }
}
