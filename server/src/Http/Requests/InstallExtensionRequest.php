<?php

namespace Fleetbase\RegistryBridge\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;

class InstallExtensionRequest extends FleetbaseRequest
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
            // A local public id or a Registry Service id, slug or package name; the installer resolves it
            'extension' => ['required', 'string', 'max:255'],
            'version'   => ['nullable', 'string', 'max:64'],
        ];
    }
}
