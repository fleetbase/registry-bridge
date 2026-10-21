<?php

namespace Fleetbase\RegistryBridge\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;

class RegistryUser extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * Developer accounts have neither a cloud user nor a company, so both are optional.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'           => $this->public_id,
            'account_type' => $this->account_type,
            'user_id'      => $this->user ? $this->user->public_id : null,
            'company_id'   => $this->company ? $this->company->public_id : null,
            'token'        => $this->token,
            'groups'       => $this->groups(),
            'updated_at'   => $this->updated_at,
            'created_at'   => $this->created_at,
        ];
    }
}
