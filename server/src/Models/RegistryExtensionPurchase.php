<?php

namespace Fleetbase\RegistryBridge\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\Money;
use Fleetbase\Models\Company;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasUuid;

class RegistryExtensionPurchase extends Model
{
    use HasUuid;
    use HasMetaAttributes;
    use HasApiModelBehavior;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'registry_extension_purchases';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'uuid',
        'company_uuid',
        'purchaser_uuid',
        'purchaser_type',
        'extension_uuid',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'is_subcription',
        'locked_price',
        'subscription_billing_period',
        'subscription_model',
        'meta',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected $casts = [
        'meta'                  => Json::class,
        'locked_price'          => Money::class,
        'is_subcription'        => 'boolean',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [];

    /**
     * Relations that should be loaded with model.
     *
     * @var array
     */
    protected $with = [];

    /**
     * Relations that should not be loaded.
     *
     * @var array
     */
    protected $without = ['company', 'extension'];

    /**
     * Get the purchaser (polymorphic relationship).
     * Can be either a Company or RegistryDeveloperAccount.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function purchaser()
    {
        return $this->morphTo('purchaser', 'purchaser_type', 'purchaser_uuid', 'uuid');
    }

    /**
     * Legacy relationship for backward compatibility.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     *
     * @deprecated Use purchaser() instead
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function extension()
    {
        return $this->belongsTo(RegistryExtension::class, 'extension_uuid', 'uuid');
    }
}
