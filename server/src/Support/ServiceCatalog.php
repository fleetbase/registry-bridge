<?php

namespace Fleetbase\RegistryBridge\Support;

use Fleetbase\RegistryBridge\Exceptions\RegistryException;
use Fleetbase\Support\Utils;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

/**
 * Explore, Installed and Purchased data read from the Registry Service (v2).
 *
 * Service listings are mapped into the shape the `RegistryExtension` resource produces so the console renders
 * them with the existing components. Instance state comes from the instance API:
 *
 * - `is_installed` and `update_available` from `GET /v1/instances/me/updates` (plus local install records),
 * - `is_purchased` from the organization's paid licences in `GET /v1/instances/me`,
 * - `current_bundle_version` from the listing's latest release.
 *
 * Everything here except `query()` and the client-backed helpers is pure, so it can be tested with recorded
 * service responses.
 */
class ServiceCatalog
{
    public const SOURCE = 'registry-service';

    public const DEFAULT_LIMIT = 30;

    public const MAX_LIMIT = 100;

    /**
     * One page of Explore, mapped, with the Explore request parameters (`query`, `category`, `page`, `limit`).
     *
     * Every published listing is returned, including those whose hosting excludes this instance's kind: they
     * carry `install_blocked_code` `hosting` and the reason, so the console can show why they cannot be
     * installed here.
     *
     * @param array<int, string> $localPackages package names installed on this instance according to local records
     *
     * @return LengthAwarePaginator<array<string, mixed>>
     *
     * @throws RegistryException
     */
    public static function query(Request $request, RegistryClient $client, RegistryConfig $config, array $localPackages = []): LengthAwarePaginator
    {
        $search        = trim(static::str($request->input('query', '')));
        $category      = trim(static::str($request->input('category', '')));
        $limit         = static::int($request->input('limit', static::DEFAULT_LIMIT), static::DEFAULT_LIMIT);
        $page          = max(1, static::int($request->input('page', 1), 1));
        $hideInstalled = Utils::castBoolean($request->input('hide_installed', false)) === true;

        $filters = array_filter([
            'q'        => $search,
            'category' => $category,
            'pricing'  => static::pricingFilter($request->input('pricing')),
            'hosting'  => static::hostingFilter($request->input('hosting')),
            'sort'     => static::sortFilter($request->input('sort')),
        ], fn ($value) => $value !== '');

        $state = static::state($client, $config, $localPackages);

        // Installed state is only known here, so hiding installed listings pages over the whole filtered catalog
        if ($hideInstalled) {
            $items = array_values(array_filter(
                static::transformMany($client->allCatalogExtensions($filters), $state, $client->host()),
                fn (array $item) => $item['is_installed'] !== true
            ));
            $limit = $limit === -1 ? max(1, count($items)) : max(1, min($limit, static::MAX_LIMIT));

            return new LengthAwarePaginator(array_slice($items, ($page - 1) * $limit, $limit), count($items), $limit, $page, [
                'path'  => $request->url(),
                'query' => $request->query(),
            ]);
        }

        if ($limit === -1) {
            $listings = $client->allCatalogExtensions($filters);
            $items    = static::transformMany($listings, $state, $client->host());

            return new LengthAwarePaginator($items, count($items), max(1, count($items)), 1, [
                'path'  => $request->url(),
                'query' => $request->query(),
            ]);
        }

        $limit  = max(1, min($limit, static::MAX_LIMIT));
        $result = $client->catalogExtensions(array_merge($filters, ['page' => $page, 'limit' => $limit]));
        $meta   = $result['meta'];

        return new LengthAwarePaginator(
            static::transformMany($result['data'], $state, $client->host()),
            static::int($meta['total'] ?? count($result['data'])),
            max(1, static::int($meta['per_page'] ?? $limit, $limit)),
            max(1, static::int($meta['current_page'] ?? $page, $page)),
            ['path' => $request->url(), 'query' => $request->query()]
        );
    }

    /**
     * Categories from the service, shaped like the `Category` resource.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RegistryException
     */
    public static function categories(RegistryClient $client): array
    {
        $categories = [];
        foreach ($client->catalogCategories() as $category) {
            $mapped = static::transformCategory($category);
            if ($mapped !== null) {
                $categories[] = $mapped;
            }
        }

        return $categories;
    }

    /**
     * One mapped listing by slug or `ext_` id, or null when the service does not have it.
     *
     * @param array<int, string> $localPackages
     *
     * @return array<string, mixed>|null
     *
     * @throws RegistryException
     */
    public static function find(string $id, RegistryClient $client, RegistryConfig $config, array $localPackages = []): ?array
    {
        $listing = $client->catalogExtension($id);
        if ($listing === null) {
            return null;
        }

        return static::transform($listing, static::state($client, $config, $localPackages), $client->host());
    }

    /**
     * Extensions installed on this instance: what the service reports in `updates` plus local install records
     * (an install whose report did not reach the service still shows), each joined with its catalog listing.
     *
     * @param array<int, array<string, mixed>> $localInstalls local records: `extension_id`, `slug`, `name`, `npm_package`, `composer_package`, `version`
     * @param array<int, string>               $localPackages
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RegistryException
     */
    public static function installed(RegistryClient $client, RegistryConfig $config, array $localInstalls = [], array $localPackages = []): array
    {
        $state    = static::state($client, $config, $localPackages);
        $listings = static::listingsById(static::safeCatalog($client));

        $installed = [];
        foreach ($state['installed'] as $id => $entry) {
            $installed[$id] = $listings[$id] ?? static::listingFromReference(static::arr($entry['extension'] ?? null), static::arr($entry['installed'] ?? null) ?: null);
        }

        foreach ($localInstalls as $local) {
            $id = static::str($local['extension_id'] ?? '');
            if ($id === '' || isset($installed[$id])) {
                continue;
            }

            $installed[$id]       = $listings[$id] ?? static::listingFromLocalInstall($local);
            $state['local_ids'][] = $id;
        }

        return static::transformMany(array_values($installed), $state, $client->host());
    }

    /**
     * Extensions the instance's organization holds an active paid licence for.
     *
     * @param array<int, string> $localPackages
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RegistryException
     */
    public static function purchased(RegistryClient $client, RegistryConfig $config, array $localPackages = []): array
    {
        $state    = static::state($client, $config, $localPackages);
        $listings = static::listingsById(static::safeCatalog($client));

        $purchased = [];
        foreach ($state['licences'] as $id => $licence) {
            if (!static::isPaidLicence($licence)) {
                continue;
            }

            $purchased[] = $listings[$id] ?? static::listingFromReference(static::arr($licence['extension'] ?? null), null);
        }

        return static::transformMany($purchased, $state, $client->host());
    }

    /**
     * Resolve a package name, slug or `ext_` id to a published listing and its package names.
     *
     * @return array{extension: array<string, mixed>, npm: string|null, composer: string|null}|null
     *
     * @throws RegistryException
     */
    public static function lookup(string $package, RegistryClient $client): ?array
    {
        $package = trim($package);
        if ($package === '') {
            return null;
        }

        $listing = static::findListingByPackage($client->allCatalogExtensions(), $package);
        if ($listing === null && !str_contains($package, '/')) {
            $listing = $client->catalogExtension($package);
        }

        if ($listing === null) {
            return null;
        }

        $packages = static::packages($listing);

        return ['extension' => $listing, 'npm' => $packages['npm'], 'composer' => $packages['composer']];
    }

    /**
     * Instance state used to decorate listings, read best-effort from the instance API.
     *
     * Without a registration (or when the service cannot be reached) the state is empty and listings show as
     * not installed and not purchased.
     *
     * @param array<int, string> $localPackages
     *
     * @return array{registered: bool, kind: string, checkout: bool, installed: array<string, array<string, mixed>>, licences: array<string, array<string, mixed>>, local_packages: array<int, string>, local_ids: array<int, string>, error: string|null}
     */
    public static function state(RegistryClient $client, RegistryConfig $config, array $localPackages = []): array
    {
        $me      = [];
        $updates = [];
        $error   = null;

        if ($client->hasToken()) {
            try {
                $me      = $client->instance();
                $updates = $client->updates();
            } catch (RegistryException $e) {
                $error = $e->getMessage();
            }
        }

        $state             = static::buildState($me, $updates, $localPackages, $client->hasToken(), $config->instanceKind(), ServiceCheckout::available($config));
        $state['receipts'] = ServiceReceipts::all($config);
        $state['error']    = $error;

        return $state;
    }

    /**
     * Index the instance API responses by extension id.
     *
     * @param array<string, mixed> $me            `GET /v1/instances/me` body
     * @param array<string, mixed> $updates       `GET /v1/instances/me/updates` body
     * @param array<int, string>   $localPackages
     * @param bool                 $checkout      whether paid listings can be bought through the service checkout
     *
     * @return array{registered: bool, kind: string, checkout: bool, installed: array<string, array<string, mixed>>, licences: array<string, array<string, mixed>>, local_packages: array<int, string>, local_ids: array<int, string>, error: string|null}
     */
    public static function buildState(array $me = [], array $updates = [], array $localPackages = [], bool $registered = false, string $kind = RegistryConfig::KIND_SELF_HOSTED, bool $checkout = false): array
    {
        $installed = [];
        foreach ((array) ($updates['data'] ?? []) as $entry) {
            $id = is_array($entry) ? static::str(Arr::get($entry, 'extension.id')) : '';
            if ($id !== '' && is_array($entry)) {
                $installed[$id] = $entry;
            }
        }

        $licences = [];
        foreach ((array) ($me['licences'] ?? []) as $licence) {
            $id = is_array($licence) ? static::str(Arr::get($licence, 'extension.id')) : '';
            if ($id === '' || !is_array($licence)) {
                continue;
            }

            // Prefer an active paid licence over a free one for the same extension
            if (!isset($licences[$id]) || static::isPaidLicence($licence)) {
                $licences[$id] = $licence;
            }
        }

        return [
            'registered'     => $registered,
            'kind'           => $kind,
            'checkout'       => $checkout,
            'receipts'       => [],
            'installed'      => $installed,
            'licences'       => $licences,
            'local_packages' => array_values(array_filter($localPackages, fn ($name) => is_string($name) && $name !== '')),
            'local_ids'      => [],
            'error'          => null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $listings
     * @param array<string, mixed>             $state
     *
     * @return array<int, array<string, mixed>>
     */
    public static function transformMany(array $listings, array $state = [], ?string $serviceUrl = null): array
    {
        return array_values(array_map(fn (array $listing) => static::transform($listing, $state, $serviceUrl), array_filter($listings, 'is_array')));
    }

    /**
     * Map one service listing (summary or full) into the local `RegistryExtension` resource shape.
     *
     * @param array<string, mixed> $listing entry from `GET /v1/catalog/extensions[/{slug}]`
     * @param array<string, mixed> $state   from `buildState()`
     *
     * @return array<string, mixed>
     */
    public static function transform(array $listing, array $state = [], ?string $serviceUrl = null): array
    {
        $id         = static::str(Arr::get($listing, 'id'));
        $slug       = static::str(Arr::get($listing, 'slug'));
        $category   = static::transformCategory(Arr::get($listing, 'category'));
        $packages   = static::packages($listing);
        $names      = array_values(array_filter([$packages['npm'], $packages['composer']]));
        $release    = is_array(Arr::get($listing, 'latest_release')) ? Arr::get($listing, 'latest_release') : [];
        $latest     = static::str(Arr::get($release, 'version')) ?: static::str(Arr::get($listing, 'latest_version'));
        $price      = is_array(Arr::get($listing, 'price')) ? Arr::get($listing, 'price') : [];
        $isPaid     = (bool) Arr::get($listing, 'is_paid', ($price['model'] ?? 'free') !== 'free');
        $hosting    = static::str(Arr::get($listing, 'hosting')) ?: 'both';
        $kind       = static::str($state['kind'] ?? RegistryConfig::KIND_SELF_HOSTED);
        $registered = (bool) ($state['registered'] ?? false);

        $update      = static::arr(static::arr($state['installed'] ?? null)[$id] ?? null) ?: null;
        $licence     = static::arr(static::arr($state['licences'] ?? null)[$id] ?? null) ?: null;
        if ($licence !== null) {
            $licence = static::withReceipt($licence, static::arr(static::arr($state['receipts'] ?? null)[$id] ?? null));
        }
        $local       = (array) ($state['local_packages'] ?? []);
        $isLocal     = in_array($id, (array) ($state['local_ids'] ?? []), true)
            || ($id !== '' && in_array($id, $local, true))
            || count(array_intersect($names, $local)) > 0;
        $isInstalled = $update !== null || $isLocal;
        $isPurchased = $licence !== null && static::isPaidLicence($licence);

        $installedVersion = $update ? static::str(Arr::get($update, 'installed.version')) : '';
        $updateVersion    = $update ? static::str(Arr::get($update, 'latest.version')) : '';

        $checkout      = (bool) ($state['checkout'] ?? false);
        $blocked       = static::installBlock($registered, $hosting, $kind, $isPaid, $isPurchased, $names, $latest, $checkout);
        $blockedReason = $blocked['reason'] ?? null;

        return [
            'id'                       => $id,
            'uuid'                     => $id,
            'public_id'                => $id,
            'company_uuid'             => null,
            'created_by_uuid'          => null,
            'registry_user_uuid'       => null,
            'category_uuid'            => $category['id'] ?? null,
            'current_bundle_uuid'      => null,
            'next_bundle_uuid'         => null,
            'icon_uuid'                => null,
            'name'                     => Arr::get($listing, 'name'),
            'subtitle'                 => Arr::get($listing, 'subtitle'),
            'description'              => Arr::get($listing, 'description', Arr::get($listing, 'subtitle')),
            'promotional_text'         => Arr::get($listing, 'promotional_text'),
            'fa_icon'                  => Arr::get($listing, 'fa_icon'),
            'icon_url'                 => Arr::get($listing, 'icon_url'),
            'slug'                     => $slug,
            'version'                  => $latest ?: null,
            'status'                   => Arr::get($listing, 'status', 'published'),
            'tags'                     => array_values((array) Arr::get($listing, 'tags', [])),
            'languages'                => array_values((array) Arr::get($listing, 'languages', [])),
            'primary_language'         => Arr::get($listing, 'languages.0'),
            'website_url'              => Arr::get($listing, 'website_url'),
            'repo_url'                 => Arr::get($listing, 'repo_url'),
            'support_url'              => Arr::get($listing, 'support_url'),
            'privacy_policy_url'       => Arr::get($listing, 'privacy_url'),
            'tos_url'                  => Arr::get($listing, 'tos_url'),
            'copyright'                => Arr::get($listing, 'copyright'),
            'payment_required'         => $isPaid,
            'price'                    => static::int($price['amount'] ?? 0),
            'sale_price'               => isset($price['sale_amount']) ? static::int($price['sale_amount']) : null,
            'on_sale'                  => (bool) ($price['on_sale'] ?? false),
            'currency'                 => static::str($price['currency'] ?? '') ?: 'USD',
            'subscription_required'    => false,
            'core_extension'           => false,
            'self_managed'             => false,
            'installs_count'           => static::int(Arr::get($listing, 'installs_count', 0)),
            'category_name'            => $category['name'] ?? null,
            'publisher_name'           => Arr::get($listing, 'publisher.name'),
            'current_bundle_version'   => $latest ?: null,
            'current_bundle_id'        => Arr::get($release, 'id'),
            'current_bundle_public_id' => Arr::get($release, 'id'),
            'current_bundle_filename'  => null,
            'next_bundle_id'           => null,
            'next_bundle_public_id'    => null,
            'next_bundle_filename'     => null,
            'category'                 => $category,
            'screenshots'              => static::screenshots(Arr::get($listing, 'screenshots', [])),
            'is_installed'             => $isInstalled,
            'is_purchased'             => $isPurchased,
            'is_author'                => false,
            'is_installable'           => $blockedReason === null,
            'installed_version'        => $installedVersion ?: null,
            'latest_version'           => $updateVersion ?: ($latest ?: null),
            'update_available'         => (bool) ($update['update_available'] ?? false),
            'source'                   => static::SOURCE,
            'meta'                     => [
                'source'                 => static::SOURCE,
                'api_version'            => RegistryConfig::V2,
                'registry_host'          => $serviceUrl,
                'packages'               => $names,
                'package_names'          => $packages,
                'publisher'              => Arr::get($listing, 'publisher'),
                'hosting'                => $hosting,
                'compat'                 => Arr::get($listing, 'compat', Arr::get($release, 'compat')),
                'latest_release'         => $release ?: null,
                'licence'                => $licence,
                'install_blocked_reason' => $blockedReason,
                'install_blocked_code'   => $blocked['code'] ?? null,
                'purchase_available'     => ($blocked['code'] ?? null) === 'licence_required' && $checkout,
                'versions'               => array_values((array) Arr::get($listing, 'versions', [])),
            ],
            'published_at'             => Arr::get($listing, 'published_at'),
            'created_at'               => Arr::get($listing, 'created_at'),
            'updated_at'               => Arr::get($listing, 'updated_at'),
        ];
    }

    /**
     * Map a service category into the `Category` resource shape.
     *
     * @return array<string, mixed>|null
     */
    public static function transformCategory(mixed $category): ?array
    {
        if (!is_array($category)) {
            return null;
        }

        $id   = static::str(Arr::get($category, 'id'));
        $name = Arr::get($category, 'name');
        if ($id === '' || !$name) {
            return null;
        }

        return [
            'id'               => $id,
            'uuid'             => $id,
            'public_id'        => $id,
            'company_uuid'     => null,
            'owner_uuid'       => null,
            'owner_type'       => null,
            'parent_uuid'      => null,
            'name'             => $name,
            'slug'             => Arr::get($category, 'slug'),
            'description'      => Arr::get($category, 'description'),
            'icon'             => Arr::get($category, 'icon'),
            'icon_color'       => null,
            'icon_url'         => null,
            'tags'             => array_values((array) Arr::get($category, 'tags', [])),
            'translations'     => [],
            'meta'             => ['source' => static::SOURCE, 'extensions_count' => Arr::get($category, 'extensions_count')],
            'for'              => 'extension_category',
            'core_category'    => 1,
            'order'            => Arr::get($category, 'sort', 0),
        ];
    }

    /**
     * Sidebar counts from the instance state: installed extensions (service records and local installs),
     * those with an update, and active paid licences.
     *
     * @param array<string, mixed> $state    from `buildState()`
     * @param array<int, string>   $localIds extension ids installed according to local records
     *
     * @return array{installed: int, updates: int, purchased: int}
     */
    public static function counts(array $state, array $localIds = []): array
    {
        $installed = static::arr($state['installed'] ?? null);
        $ids       = array_unique(array_merge(array_map('strval', array_keys($installed)), array_values(array_filter($localIds, 'is_string'))));
        $updates   = array_filter($installed, fn ($entry) => is_array($entry) && ($entry['update_available'] ?? false) === true);
        $purchased = array_filter(static::arr($state['licences'] ?? null), fn ($licence) => is_array($licence) && static::isPaidLicence($licence));

        return ['installed' => count($ids), 'updates' => count($updates), 'purchased' => count($purchased)];
    }

    /**
     * A licence with `receipt_url` (and `purchase`) filled in: from the licence itself when the service sends it
     * (`receipt_url`, or a `purchase` object with one), else from the receipt this instance kept when its
     * checkout completed.
     *
     * @param array<string, mixed> $licence
     * @param array<string, mixed> $stored  `ServiceReceipts` record: `receipt_url`, `purchase`, `amount_total`, `currency`, `purchased_at`
     *
     * @return array<string, mixed>
     */
    public static function withReceipt(array $licence, array $stored = []): array
    {
        $purchase = is_array($licence['purchase'] ?? null) ? $licence['purchase'] : [];
        $receipt  = static::str($licence['receipt_url'] ?? '') ?: (static::str($purchase['receipt_url'] ?? '') ?: (static::str($stored['receipt_url'] ?? '') ?: null));

        $licence['receipt_url'] = $receipt;
        if ($purchase === [] && $stored !== []) {
            $licence['purchase'] = array_filter([
                'id'           => $stored['purchase'] ?? (is_string($licence['purchase'] ?? null) ? $licence['purchase'] : null),
                'amount_total' => $stored['amount_total'] ?? null,
                'currency'     => $stored['currency'] ?? null,
                'receipt_url'  => $receipt,
                'created_at'   => $stored['purchased_at'] ?? null,
            ], fn ($value) => $value !== null);
        }

        return $licence;
    }

    /**
     * The service `pricing` filter: `free`, `paid`, or empty for both.
     */
    public static function pricingFilter(mixed $pricing): string
    {
        $pricing = strtolower(trim(static::str($pricing)));

        return in_array($pricing, ['free', 'paid'], true) ? $pricing : '';
    }

    /**
     * The service `hosting` filter: `cloud` or `self_hosted` (listings for that kind, `both` included) or `both`
     * (listings for either kind); empty for every listing.
     */
    public static function hostingFilter(mixed $hosting): string
    {
        $hosting = strtolower(trim(static::str($hosting)));

        return in_array($hosting, [RegistryConfig::KIND_CLOUD, RegistryConfig::KIND_SELF_HOSTED, 'both'], true) ? $hosting : '';
    }

    /**
     * The service `sort`: `popular` (default), `newest`, `updated`, `name` or `price`.
     */
    public static function sortFilter(mixed $sort): string
    {
        $sort = strtolower(trim(static::str($sort)));

        return in_array($sort, ['popular', 'newest', 'updated', 'name', 'price'], true) ? $sort : '';
    }

    /**
     * Releases of a listing for the console's Versions tab, newest first, or null when the service does not
     * have the listing.
     *
     * @return array{latest: string|null, channels: array<string, string>, releases: array<int, array<string, mixed>>}|null
     *
     * @throws RegistryException
     */
    public static function releases(string $id, RegistryClient $client, ?string $installedVersion = null): ?array
    {
        $payload = $client->catalogReleases($id);
        if ($payload === null) {
            return null;
        }

        return static::transformReleases($payload, $installedVersion);
    }

    /**
     * Map a releases payload: each release with `is_latest`, `is_installed` and `is_yanked` flags.
     *
     * @param array<string, mixed> $payload `{latest, channels, releases}` from the service
     *
     * @return array{latest: string|null, channels: array<string, string>, releases: array<int, array<string, mixed>>}
     */
    public static function transformReleases(array $payload, ?string $installedVersion = null): array
    {
        $latest   = static::str($payload['latest'] ?? '') ?: null;
        $releases = [];
        foreach ((array) ($payload['releases'] ?? []) as $release) {
            if (!is_array($release) || static::str($release['version'] ?? '') === '') {
                continue;
            }

            $version    = static::str($release['version']);
            $releases[] = [
                'id'           => $release['id'] ?? null,
                'version'      => $version,
                'channel'      => static::str($release['channel'] ?? '') ?: 'stable',
                'status'       => static::str($release['status'] ?? '') ?: 'published',
                'compat'       => is_array($release['compat'] ?? null) ? $release['compat'] : [],
                'size_bytes'   => static::int($release['size_bytes'] ?? 0),
                'notes'        => $release['notes'] ?? null,
                'published_at' => $release['published_at'] ?? null,
                'yanked_at'    => $release['yanked_at'] ?? null,
                'is_latest'    => $version === $latest,
                'is_installed' => $installedVersion !== null && $version === $installedVersion,
                'is_yanked'    => ($release['status'] ?? null) === 'yanked' || ($release['yanked_at'] ?? null) !== null,
            ];
        }

        return [
            'latest'   => $latest,
            'channels' => array_filter((array) ($payload['channels'] ?? []), 'is_string'),
            'releases' => $releases,
        ];
    }

    /**
     * `{npm, composer}` package names a listing declares.
     *
     * @param array<string, mixed> $listing
     *
     * @return array{npm: string|null, composer: string|null}
     */
    public static function packages(array $listing): array
    {
        $npm      = static::str(Arr::get($listing, 'packages.npm'));
        $composer = static::str(Arr::get($listing, 'packages.composer'));

        return ['npm' => $npm ?: null, 'composer' => $composer ?: null];
    }

    /**
     * The listing whose package names, slug or id match.
     *
     * @param array<int, array<string, mixed>> $listings
     *
     * @return array<string, mixed>|null
     */
    public static function findListingByPackage(array $listings, string $package): ?array
    {
        foreach ($listings as $listing) {
            $packages   = static::packages($listing);
            $candidates = [$packages['npm'], $packages['composer'], Arr::get($listing, 'slug'), Arr::get($listing, 'id')];
            if (in_array($package, array_filter($candidates, 'is_string'), true)) {
                return $listing;
            }
        }

        return null;
    }

    /**
     * Why this instance cannot install a listing, or null when it can.
     *
     * @param array<int, string> $packageNames
     */
    public static function installBlockedReason(bool $registered, string $hosting, string $kind, bool $isPaid, bool $isPurchased, array $packageNames, string $version, bool $checkout = false): ?string
    {
        return static::installBlock($registered, $hosting, $kind, $isPaid, $isPurchased, $packageNames, $version, $checkout)['reason'] ?? null;
    }

    /**
     * Why this instance cannot install a listing, as a code the console branches on (`not_registered`,
     * `hosting`, `licence_required`, `no_release`) and the sentence it shows, or null when it can.
     *
     * @param array<int, string> $packageNames
     *
     * @return array{code: string, reason: string}|null
     */
    public static function installBlock(bool $registered, string $hosting, string $kind, bool $isPaid, bool $isPurchased, array $packageNames, string $version, bool $checkout = false): ?array
    {
        if (!$registered) {
            return ['code' => 'not_registered', 'reason' => 'Register this instance with the registry service to install extensions.'];
        }

        if ($hosting !== 'both' && $hosting !== $kind) {
            return ['code' => 'hosting', 'reason' => $hosting === RegistryConfig::KIND_CLOUD
                ? 'This extension is only available on Fleetbase Cloud.'
                : 'This extension is only available on self-hosted instances.'];
        }

        if ($isPaid && !$isPurchased) {
            return ['code' => 'licence_required', 'reason' => $checkout
                ? 'Purchase a licence for your organization to install this extension.'
                : 'Your organization does not hold a licence for this extension. Purchasing through the registry service is not available yet.'];
        }

        if ($packageNames === [] || $version === '') {
            return ['code' => 'no_release', 'reason' => 'This extension has no published release to install.'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $licence
     */
    public static function isPaidLicence(array $licence): bool
    {
        return ($licence['kind'] ?? null) === 'paid' && ($licence['status'] ?? null) === 'active';
    }

    /**
     * Catalog listings or none when the service is unreachable, for joins that can degrade to references.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function safeCatalog(RegistryClient $client): array
    {
        try {
            return $client->allCatalogExtensions();
        } catch (RegistryException $e) {
            return [];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $listings
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function listingsById(array $listings): array
    {
        $indexed = [];
        foreach ($listings as $listing) {
            $id = static::str(Arr::get($listing, 'id'));
            if ($id !== '') {
                $indexed[$id] = $listing;
            }
        }

        return $indexed;
    }

    /**
     * A minimal listing from an `{id, slug, name}` reference, for extensions no longer in the catalog.
     *
     * @param array<string, mixed>      $reference
     * @param array<string, mixed>|null $release
     *
     * @return array<string, mixed>
     */
    protected static function listingFromReference(array $reference, ?array $release): array
    {
        return [
            'id'             => Arr::get($reference, 'id'),
            'slug'           => Arr::get($reference, 'slug'),
            'name'           => Arr::get($reference, 'name'),
            'status'         => 'unlisted',
            'latest_release' => $release,
        ];
    }

    /**
     * @param array<string, mixed> $local
     *
     * @return array<string, mixed>
     */
    protected static function listingFromLocalInstall(array $local): array
    {
        return [
            'id'             => Arr::get($local, 'extension_id'),
            'slug'           => Arr::get($local, 'slug'),
            'name'           => Arr::get($local, 'name'),
            'status'         => 'unlisted',
            'packages'       => ['npm' => Arr::get($local, 'npm_package'), 'composer' => Arr::get($local, 'composer_package')],
            'latest_release' => ['id' => Arr::get($local, 'release_id'), 'version' => Arr::get($local, 'version')],
        ];
    }

    /**
     * Service screenshots (`{id, url, name, mime}`) as file-like records.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function screenshots(mixed $screenshots): array
    {
        if (!is_array($screenshots)) {
            return [];
        }

        $files = [];
        foreach ($screenshots as $screenshot) {
            if (!is_array($screenshot) || !isset($screenshot['url'])) {
                continue;
            }

            $files[] = [
                'id'                => $screenshot['id'] ?? null,
                'uuid'              => $screenshot['id'] ?? null,
                'public_id'         => $screenshot['id'] ?? null,
                'url'               => $screenshot['url'],
                'original_filename' => $screenshot['name'] ?? null,
                'content_type'      => $screenshot['mime'] ?? null,
            ];
        }

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    protected static function str(mixed $value): string
    {
        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }

    protected static function int(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
