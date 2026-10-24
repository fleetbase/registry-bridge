<?php

namespace Fleetbase\RegistryBridge\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;

/**
 * Resource for an extension read from the central registry.
 *
 * The underlying resource is already an array in the `RegistryExtension` shape
 * (see `CentralCatalog::transform()`), so it is passed through untouched.
 *
 * @see \Fleetbase\RegistryBridge\Support\CentralCatalog
 */
class RemoteRegistryExtension extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        return is_array($this->resource) ? $this->resource : (array) $this->resource;
    }
}
