<?php

namespace Fleetbase\RegistryBridge\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\Money;
use Fleetbase\Models\Category;
use Fleetbase\Models\Company;
use Fleetbase\Models\File;
use Fleetbase\Models\Model;
use Fleetbase\Models\User;
use Fleetbase\RegistryBridge\Support\Publisher;
use Fleetbase\RegistryBridge\Support\Purchaser;
use Fleetbase\RegistryBridge\Support\Utils;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Support\Str;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class RegistryExtension extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasMetaAttributes;
    use HasApiModelBehavior;
    use HasSlug;
    use Searchable;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'registry_extensions';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'extension';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'uuid',
        'company_uuid',
        'publisher_type',
        'publisher_uuid',
        'created_by_uuid',
        'category_uuid',
        'registry_user_uuid',
        'current_bundle_uuid',
        'next_bundle_uuid',
        'icon_uuid',
        'public_id',
        'stripe_product_id',
        'name',
        'package_name',
        'subtitle',
        'self_managed',
        'payment_required',
        'price',
        'sale_price',
        'on_sale',
        'subscription_required',
        'subscription_billing_period',
        'subscription_model',
        'subscription_amount',
        'subscription_tiers',
        'currency',
        'slug',
        'version',
        'fa_icon',
        'description',
        'promotional_text',
        'website_url',
        'repo_url',
        'support_url',
        'privacy_policy_url',
        'tos_url',
        'copyright',
        'primary_language',
        'tags',
        'languages',
        'meta',
        'core_extension',
        'status',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected $casts = [
        'self_managed'          => 'boolean',
        'payment_required'      => 'boolean',
        'on_sale'               => 'boolean',
        'subscription_required' => 'boolean',
        'subscription_tiers'    => Json::class,
        'tags'                  => Json::class,
        'languages'             => Json::class,
        'meta'                  => Json::class,
        'core_extension'        => 'boolean',
        'price'                 => Money::class,
        'sale_price'            => Money::class,
        'subscription_amount'   => Money::class,
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [
        'icon_url',
        'current_bundle_filename',
        'current_bundle_id',
        'current_bundle_public_id',
        'current_bundle_version',
        'next_bundle_filename',
        'next_bundle_id',
        'next_bundle_public_id',
        'category_name',
        'publisher_name',
        'is_purchased',
        'is_installed',
        'is_author',
    ];

    /**
     * Relations that should be loaded with model.
     *
     * @var array
     */
    protected $with = [
        'category',
    ];

    /**
     * Relations that should not be loaded.
     *
     * @var array
     */
    protected $without = [
        'current_bundle',
        'next_bundle',
    ];

    /**
     * Searchable columns.
     *
     * @var array
     */
    protected $searchableColumns = ['name'];

    /**
     * The "booting" method of the model.
     *
     * This method is called on the model boot and sets up
     * event listeners, such as creating a unique bundle ID
     * when a new model instance is being created.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            if ($model->isDirty('price')) {
                $model->updateOrCreateStripePrice();
            }
        });
    }

    /**
     * Get the options for generating the slug.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function registryUser()
    {
        return $this->belongsTo(RegistryUser::class, 'registry_user_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function currentBundle()
    {
        return $this->belongsTo(RegistryExtensionBundle::class, 'current_bundle_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function nextBundle()
    {
        return $this->belongsTo(RegistryExtensionBundle::class, 'next_bundle_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function icon()
    {
        return $this->belongsTo(File::class, 'icon_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function screenshots()
    {
        return $this->hasMany(File::class, 'subject_uuid', 'uuid')->where('type', 'extension_screenshot');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function bundles()
    {
        return $this->hasMany(RegistryExtensionBundle::class, 'extension_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function bundleFiles()
    {
        return $this->hasMany(File::class, 'subject_uuid', 'uuid')->where('type', 'extension_bundle');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function installs()
    {
        return $this->hasMany(RegistryExtensionInstall::class, 'extension_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function purchases()
    {
        return $this->hasMany(RegistryExtensionPurchase::class, 'extension_uuid', 'uuid');
    }

    /**
     * Check if current session has purchased extension.
     */
    public function getIsPurchasedAttribute(): bool
    {
        return $this->purchases->contains(function ($purchase) {
            return $purchase->company_uuid === session('company');
        });
    }

    /**
     * Check if current session has installed extension.
     */
    public function getIsInstalledAttribute(): bool
    {
        return $this->installs->contains(function ($purchase) {
            return $purchase->company_uuid === session('company');
        });
    }

    /**
     * Get avatar URL attribute.
     */
    public function getIconUrlAttribute(): ?string
    {
        if ($this->icon instanceof File) {
            return $this->icon->url;
        }

        return data_get($this, 'icon.url', 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/default-extension-icon.svg');
    }

    /**
     * Get the current bundle public ID.
     */
    public function getCurrentBundlePublicIdAttribute(): ?string
    {
        if ($this->currentBundle instanceof RegistryExtensionBundle) {
            return $this->currentBundle->public_id;
        }

        return data_get($this, 'currentBundle.public_id');
    }

    /**
     * Get the current bundle ID.
     */
    public function getCurrentBundleIdAttribute(): ?string
    {
        if ($this->currentBundle instanceof RegistryExtensionBundle) {
            return $this->currentBundle->bundle_id;
        }

        return data_get($this, 'currentBundle.bundle_id');
    }

    /**
     * Get the current bundle original filename.
     */
    public function getCurrentBundleFilenameAttribute(): ?string
    {
        if ($this->currentBundle instanceof RegistryExtensionBundle) {
            return $this->currentBundle->bundle_filename;
        }

        return data_get($this, 'currentBundle.bundle_filename');
    }

    /**
     * Get the current bundle version.
     */
    public function getCurrentBundleVersionAttribute(): ?string
    {
        if ($this->currentBundle instanceof RegistryExtensionBundle) {
            return $this->currentBundle->version;
        }

        return data_get($this, 'currentBundle.version');
    }

    /**
     * Get the next bundle public ID.
     */
    public function getNextBundlePublicIdAttribute(): ?string
    {
        if ($this->nextBundle instanceof RegistryExtensionBundle) {
            return $this->nextBundle->public_id;
        }

        return data_get($this, 'nextBundle.public_id');
    }

    /**
     * Get the next bundle ID.
     */
    public function getNextBundleIdAttribute(): ?string
    {
        if ($this->nextBundle instanceof RegistryExtensionBundle) {
            return $this->nextBundle->bundle_id;
        }

        return data_get($this, 'nextBundle.bundle_id');
    }

    /**
     * Get the current bundle original filename.
     */
    public function getNextBundleFilenameAttribute(): ?string
    {
        if ($this->nextBundle instanceof RegistryExtensionBundle) {
            return $this->nextBundle->bundle_filename;
        }

        return data_get($this, 'nextBundle.bundle_filename');
    }

    /**
     * Get the extension's category.
     */
    public function getCategoryNameAttribute(): ?string
    {
        if ($this->category instanceof Category) {
            return $this->category->name;
        }

        return data_get($this, 'category.name');
    }

    /**
     * Get the extension's category.
     */
    public function getPublisherNameAttribute(): ?string
    {
        if ($this->company instanceof Company) {
            return $this->company->name;
        }

        return data_get($this, 'company.name');
    }

    /**
     * Determines if the current company session is the author of the extension.
     */
    public function getIsAuthorAttribute(): bool
    {
        return $this->company_uuid === session('company');
    }

    /**
     * Finds a RegistryExtension by package name.
     *
     * The extension's own `package_name` column is checked first (set on first publish, before
     * any bundle exists). Otherwise the associated currentBundle's 'meta' JSON column is searched
     * for the package name in either the `package.json` or `composer.json` name.
     *
     * @param string $packageName the npm or composer package name to search for
     *
     * @return RegistryExtension|null the first RegistryExtension that matches the search criteria, or null if no match is found
     */
    public static function findByPackageName(string $packageName): ?RegistryExtension
    {
        $extension = static::where('package_name', $packageName)->first();
        if ($extension) {
            return $extension;
        }

        return static::whereHas('currentBundle', function ($query) use ($packageName) {
            $query->where('meta->package.json->name', $packageName)->orWhere('meta->composer.json->name', $packageName);
        })->first();
    }

    /**
     * Lookup a registry extension based on the given package name.
     *
     * This method attempts to find a `RegistryExtension` that matches the provided package name. It checks multiple fields including
     * `uuid`, `public_id`, and `slug`. If the package name starts with 'fleetbase/', it also attempts to match the slug extracted from the package name.
     *
     * Additionally, the method checks for the existence of a related `currentBundle` where the `package.json` or `composer.json` metadata
     * matches the provided package name.
     *
     * @param string $packageName the name, UUID, public ID, or slug of the package to lookup
     *
     * @return RegistryExtension|null returns the found `RegistryExtension` instance or `null` if no match is found
     */
    public static function lookup(string $packageName): ?RegistryExtension
    {
        return static::where('status', 'published')->where(function ($query) use ($packageName) {
            $query->where('uuid', $packageName)
                  ->orWhere('public_id', $packageName)
                  ->orWhere('slug', $packageName);

            // Check for fleetbase/ prefix and match slug
            if (Str::startsWith($packageName, 'fleetbase/')) {
                $packageSlug = explode('/', $packageName)[1] ?? null;
                if ($packageSlug) {
                    $query->orWhere('slug', $packageSlug);
                }
            }
        })->orWhereHas('currentBundle', function ($query) use ($packageName) {
            $query->where('meta->package.json->name', $packageName)
                  ->orWhere('meta->composer.json->name', $packageName);
        })->with(['currentBundle'])->first();
    }

    /**
     * Determines if the current extension instance is ready for submission.
     *
     * This method is an instance method that internally calls the static method
     * `isExtensionReadyForSubmission` to perform the validation on the current instance.
     * It checks various fields of the extension for certain criteria such as minimum
     * string lengths, presence of necessary fields, and URL validation.
     *
     * @return bool returns true if the extension instance passes all validations, false otherwise
     */
    public function isReadyForSubmission(): bool
    {
        return static::isExtensionReadyForSubmission($this);
    }

    /**
     * Validates if an extension, identified by its ID or instance, is ready for submission.
     *
     * This method accepts either an extension ID or an instance of `RegistryExtension`. It then
     * performs various validations on fields like 'name', 'description', 'tags', etc., to
     * determine if the extension meets the criteria for submission. URL fields are validated
     * to ensure they contain proper URLs. The method is designed to be flexible, handling
     * validation for both an extension ID and an extension object.
     *
     * @param int|RegistryExtension $extensionId the ID of the extension or an instance of `RegistryExtension`
     *
     * @return bool returns true if the extension passes all validations, false otherwise
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException if the extension with the given ID is not found
     */
    public static function isExtensionReadyForSubmission($extensionId): bool
    {
        if ($extensionId instanceof RegistryExtension) {
            $extension = $extensionId;
        } else {
            $extension = self::find($extensionId);
        }
        if (!$extension) {
            return false;
        }

        $validations = [
            'name' => function ($value) {
                return is_string($value) && strlen($value) > 3;
            },
            'description' => function ($value) {
                return is_string($value) && strlen($value) > 12;
            },
            'tags' => function ($value) {
                return !empty($value);
            },
            'promotional_text' => function ($value) {
                return !empty($value);
            },
            'subtitle' => function ($value) {
                return !empty($value);
            },
            'copyright' => function ($value) {
                return !empty($value);
            },
            'website_url' => function ($value) {
                return filter_var($value, FILTER_VALIDATE_URL) !== false;
            },
            'support_url' => function ($value) {
                return filter_var($value, FILTER_VALIDATE_URL) !== false;
            },
            'privacy_policy_url' => function ($value) {
                return filter_var($value, FILTER_VALIDATE_URL) !== false;
            },
            'icon_uuid' => function ($value) {
                return !empty($value);
            },
            'category_uuid' => function ($value) {
                return !empty($value);
            },
            'next_bundle_uuid' => function ($value) {
                return !empty($value);
            },
        ];

        // Should have a new bundle for submission
        $isNewBundle = $extension->next_bundle_uuid !== $extension->current_bundle_uuid;
        if (!$isNewBundle) {
            return false;
        }

        // Check validations
        foreach ($validations as $field => $validationFunction) {
            if (isset($extension->$field)) {
                $value = $extension->$field;
                if (!$validationFunction($value)) {
                    return false;
                }
            } else {
                return false;
            }
        }

        return true;
    }

    /**
     * Retrieves or creates a Stripe product associated with this model instance.
     *
     * This method attempts to fetch a Stripe product based on an existing 'stripe_product_id'.
     * If no product is found or if 'stripe_product_id' is not set, it creates a new Stripe product
     * with the current model's name and description and updates the model's 'stripe_product_id'.
     *
     * @return \Stripe\Product|null returns the Stripe Product object if successful, or null if the Stripe client is not available
     */
    public function getStripeProduct(): ?\Stripe\Product
    {
        $stripe = Utils::getStripeClient();
        if ($stripe) {
            $product = $this->stripe_product_id ? $stripe->products->retrieve($this->stripe_product_id, []) : null;
            if (!$product) {
                $product = $stripe->products->create(['name' => $this->name, 'description' => $this->description, 'metadata' => ['fleetbase_id' => $this->public_id]]);
            }

            // Update stripe product id
            if ($this->exists) {
                $this->update(['stripe_product_id' => $product->id]);
            } else {
                $this->setAttribute('stripe_product_id', $product->id);
            }

            return $product;
        }

        return null;
    }

    /**
     * Retrieves the active Stripe price for the associated product.
     *
     * This method first ensures the product exists in Stripe by calling getStripeProduct().
     * It then fetches the current active price for the product. If no active prices are found,
     * it returns null.
     *
     * @return \Stripe\Price|null returns the Stripe Price object if available, or null otherwise
     */
    public function getStripePrice(): ?\Stripe\Price
    {
        if (!$this->stripe_product_id) {
            $this->getStripeProduct();
        }

        $stripe  = Utils::getStripeClient();
        $price   = null;
        if ($stripe) {
            $prices = $stripe->prices->all(['product' => $this->stripe_product_id, 'limit' => 1, 'active' => true]);
            $price  = is_array($prices->data) && count($prices->data) ? $prices->data[0] : null;
        }

        return $price;
    }

    /**
     * Updates the existing active Stripe price to inactive and creates a new Stripe price.
     *
     * This method first retrieves the current active price and deactivates it if it exists.
     * It then creates a new price with the current model's price and currency, associated with
     * the Stripe product.
     *
     * @return \Stripe\Price|null returns the newly created Stripe Price object
     */
    public function updateOrCreateStripePrice(): ?\Stripe\Price
    {
        $stripe = Utils::getStripeClient();
        $price  = $this->getStripePrice();
        if ($price instanceof \Stripe\Price) {
            // update stripe price
            $stripe->prices->update($price->id, ['active' => false]);
        }

        // create new stripe price
        $price = $stripe->prices->create(['unit_amount' => $this->price, 'currency' => $this->currency, 'product' => $this->stripe_product_id]);

        return $price;
    }

    /**
     * Creates a Stripe Checkout session for purchasing this model's associated product.
     *
     * This method ensures that the model has a valid company (extension author) and an active price.
     * It calculates the facilitator fee based on the total amount and creates a checkout session with
     * the necessary Stripe configurations, including the return URI with query parameters.
     *
     * @param string         $returnUri the URI to which the user should be returned after the checkout process
     * @param Purchaser|null $purchaser the purchaser (company or developer account); defaults to the session company
     *
     * @return \Stripe\Checkout\Session returns the Stripe Checkout Session object
     *
     * @throws \Exception throws an exception if the model does not have an associated company or price
     */
    public function createStripeCheckoutSession(string $returnUri, ?Purchaser $purchaser = null): \Stripe\Checkout\Session
    {
        // Get extension author
        $extensionAuthor = $this->company;
        if (!$extensionAuthor) {
            throw new \Exception('The extension you attempted to purchase is not available for purchase at this time.');
        }

        // Identify the purchaser so the Stripe webhook can record the purchase without a browser session
        if (!$purchaser && session('company')) {
            $purchaser = Purchaser::company(session('company'));
        }
        if (!$purchaser) {
            throw new \Exception('Unable to identify purchaser. Please ensure you are logged in.');
        }

        $metadata = array_merge($purchaser->toArray(), ['extension_uuid' => $this->uuid]);

        // Get the extension price from stripe
        $price = $this->getStripePrice();
        if (!$price) {
            throw new \Exception('The extension you attempted to purchase is not available for purchase at this time.');
        }

        // Calculate the fee fleetbase takes for faciliation of extension
        $totalAmount    = $price->unit_amount;
        $facilitatorFee = Utils::calculatePercentage(config('registry-bridge.facilitator_fee', 10), $totalAmount);

        // Get the stripe client to create the checkout session
        $stripe          = Utils::getStripeClient();

        // Create the stripe checkout session
        $checkoutSession = $stripe->checkout->sessions->create([
            'ui_mode'    => 'embedded',
            'line_items' => [
                [
                    'price'    => $price->id,
                    'quantity' => 1,
                ],
            ],
            'mode'                => 'payment',
            'return_url'          => Utils::consoleUrl($returnUri) . '?extension_id=' . $this->uuid . '&checkout_session_id={CHECKOUT_SESSION_ID}',
            'metadata'            => $metadata,
            'payment_intent_data' => [
                'application_fee_amount' => $facilitatorFee,
                'transfer_data'          => [
                    'destination' => $extensionAuthor->stripe_connect_id,
                ],
                'metadata' => $metadata,
            ],
        ]);

        return $checkoutSession;
    }

    /**
     * Determine if the registry user publishes (owns) this extension.
     *
     * Compares `publisher_type` / `publisher_uuid` against the registry user's publisher,
     * falling back to `company_uuid` for rows created before publisher fields existed.
     *
     * @param RegistryUser $registryUser the registry user to check ownership for
     */
    public function isOwnedBy(RegistryUser $registryUser): bool
    {
        $publisher = $registryUser->publisher();
        if (!$publisher) {
            return false;
        }

        return $publisher->owns($this->publisher_type, $this->publisher_uuid, $this->company_uuid);
    }

    /**
     * Determine if the registry user has access to the registry extension.
     *
     * This method checks if the extension requires payment. If it does not,
     * access is granted. If payment is required, it checks if the user's purchaser
     * (company or developer account) has made a purchase of the extension.
     *
     * @param RegistryUser $registryUser the registry user to check access for
     *
     * @return bool true if the user has access, false otherwise
     */
    public function hasAccess(RegistryUser $registryUser): bool
    {
        if (!$this->payment_required) {
            return true;
        }

        $purchaser = $registryUser->purchaser();
        if (!$purchaser) {
            return false;
        }

        return $this->purchases()->forPurchaser($purchaser)->exists();
    }

    /**
     * Determine if the registry user does not have access to the registry extension.
     *
     * This method checks if the extension requires payment. If it does not,
     * access is never denied. If payment is required, it checks if the user's
     * purchaser (company or developer account) has not made a purchase of the extension.
     *
     * @param RegistryUser $registryUser the registry user to check access for
     *
     * @return bool true if the user does not have access, false otherwise
     */
    public function doesntHaveAccess(RegistryUser $registryUser): bool
    {
        if (!$this->payment_required) {
            return false;
        }

        return !$this->hasAccess($registryUser);
    }
}
