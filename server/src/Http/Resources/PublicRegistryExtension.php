<?php

namespace Fleetbase\RegistryBridge\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;

class PublicRegistryExtension extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * Only exposes fields that are safe for public consumption.
     * No UUIDs, no Stripe data, no internal IDs, no bundle file relationships,
     * no purchase/install relationships, no sensitive company data.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public function toArray($request)
    {
        return [
            // Core identity (public_id only, no uuid)
            'id'                    => $this->public_id,
            'slug'                  => $this->slug,
            'name'                  => $this->name,
            'subtitle'              => $this->subtitle,
            'description'           => $this->description,
            'promotional_text'      => $this->promotional_text,
            'fa_icon'               => $this->fa_icon,
            'icon_url'              => $this->icon_url,
            'tags'                  => $this->tags ?? [],
            'languages'             => $this->languages ?? [],
            'primary_language'      => $this->primary_language,
            'version'               => $this->version,
            'status'                => $this->status,

            // URLs
            'website_url'           => $this->website_url,
            'repo_url'              => $this->repo_url,
            'support_url'           => $this->support_url,
            'privacy_policy_url'    => $this->privacy_policy_url,
            'tos_url'               => $this->tos_url,
            'copyright'             => $this->copyright,

            // Pricing (public-safe fields only, no Stripe IDs)
            'payment_required'      => $this->payment_required,
            'price'                 => $this->price,
            'sale_price'            => $this->sale_price,
            'on_sale'               => $this->on_sale,
            'currency'              => $this->currency,
            'subscription_required' => $this->subscription_required,

            // Flags
            'core_extension'        => $this->core_extension,
            'self_managed'          => $this->self_managed,

            // Stats
            'installs_count'        => $this->installs_count ?? 0,

            // Timestamps
            'published_at'          => $this->published_at,
            'created_at'            => $this->created_at,
            'updated_at'            => $this->updated_at,

            // Publisher (minimal safe fields only)
            'publisher'             => $this->when($this->relationLoaded('company') && $this->company, [
                'name'        => data_get($this, 'company.name'),
                'slug'        => data_get($this, 'company.slug'),
                'type'        => data_get($this, 'company.type'),
                'website_url' => data_get($this, 'company.website_url'),
                'description' => data_get($this, 'company.description'),
            ]),

            // Category (safe fields only, no UUIDs)
            'category'              => $this->when($this->relationLoaded('category') && $this->category, [
                'id'          => data_get($this, 'category.public_id'),
                'name'        => data_get($this, 'category.name'),
                'slug'        => data_get($this, 'category.slug'),
                'description' => data_get($this, 'category.description'),
                'icon'        => data_get($this, 'category.icon'),
                'icon_color'  => data_get($this, 'category.icon_color'),
                'tags'        => data_get($this, 'category.tags', []),
            ]),

            // Current bundle (status, version, meta, bundle_number only - no file relationships, no UUIDs)
            'current_bundle'        => $this->when($this->relationLoaded('currentBundle') && $this->currentBundle, [
                'bundle_number' => data_get($this, 'currentBundle.bundle_number'),
                'version'       => data_get($this, 'currentBundle.version'),
                'status'        => data_get($this, 'currentBundle.status'),
                'meta'          => data_get($this, 'currentBundle.meta'),
            ]),
        ];
    }
}
