<?php

namespace Fleetbase\RegistryBridge\Support;

use Fleetbase\RegistryBridge\Exceptions\RegistryUnavailableException;
use Fleetbase\RegistryBridge\Models\RegistryExtensionInstall;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Explore data sourced from the central registry instead of the local `registry_extensions` tables.
 *
 * Remote catalog entries are mapped into the same shape the `RegistryExtension` resource produces so the
 * console renders them with the existing components. `is_installed` is derived from the local install
 * rows by package name; `is_purchased` is always false and the records are flagged as not installable
 * until the Registry Service exists.
 */
class CentralCatalog
{
    public const SOURCE = 'registry';

    public const DEFAULT_LIMIT = 30;

    public const MAX_LIMIT = 100;

    /**
     * Whether Explore should read from the central registry.
     */
    public static function enabled(): bool
    {
        return RegistryClient::usesCentralCatalog();
    }

    /**
     * Query the remote catalog with the Explore request parameters (`query`, `category`, `page`, `limit`).
     *
     * @return LengthAwarePaginator<array<string, mixed>>
     *
     * @throws RegistryUnavailableException
     */
    public static function query(Request $request, ?RegistryClient $client = null): LengthAwarePaginator
    {
        $client     = $client ?? new RegistryClient();
        $extensions = static::all($client, static::str($request->hasSession() ? $request->session()->get('company') : session('company')));

        $search   = trim(static::str($request->input('query', '')));
        $category = trim(static::str($request->input('category', '')));

        if ($search !== '') {
            $extensions = array_values(array_filter($extensions, fn (array $extension) => static::matchesSearch($extension, $search)));
        }

        if ($category !== '') {
            $extensions = array_values(array_filter($extensions, fn (array $extension) => static::matchesCategory($extension, $category)));
        }

        $limit = static::int($request->input('limit', static::DEFAULT_LIMIT), static::DEFAULT_LIMIT);
        $limit = $limit === -1 ? max(1, count($extensions)) : max(1, min($limit, static::MAX_LIMIT));
        $page  = max(1, static::int($request->input('page', 1), 1));
        $items = array_slice($extensions, ($page - 1) * $limit, $limit);

        return new LengthAwarePaginator($items, count($extensions), $limit, $page, [
            'path'  => $request->url(),
            'query' => $request->query(),
        ]);
    }

    /**
     * Every remote extension, mapped, for the given company.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RegistryUnavailableException
     */
    public static function all(?RegistryClient $client = null, ?string $companyUuid = null): array
    {
        $client    = $client ?? new RegistryClient();
        $installed = static::installedPackageNames($companyUuid);

        return array_map(
            fn (array $extension) => static::transform($extension, $installed, $client->host()),
            $client->listPublicExtensions()
        );
    }

    /**
     * Find a single mapped remote extension by its public id or slug.
     *
     * @return array<string, mixed>|null
     */
    public static function find(?string $id, ?RegistryClient $client = null): ?array
    {
        if (!$id) {
            return null;
        }

        try {
            $client = $client ?? new RegistryClient();
            foreach ($client->listPublicExtensions() as $extension) {
                if (($extension['id'] ?? null) === $id || ($extension['slug'] ?? null) === $id) {
                    return static::transform($extension, [], $client->host());
                }
            }
        } catch (RegistryUnavailableException $e) {
            return null;
        }

        return null;
    }

    /**
     * Distinct categories referenced by the remote catalog, shaped like the `Category` resource.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RegistryUnavailableException
     */
    public static function categories(?RegistryClient $client = null): array
    {
        $client     = $client ?? new RegistryClient();
        $categories = [];

        foreach ($client->listPublicExtensions() as $extension) {
            $category = static::transformCategory(Arr::get($extension, 'category'));
            if ($category === null) {
                continue;
            }

            $key = $category['slug'] ?: $category['id'];
            if (!isset($categories[$key])) {
                $categories[$key] = $category;
            }
        }

        $categories = array_values($categories);
        usort($categories, fn (array $a, array $b) => strcasecmp(static::str($a['name']), static::str($b['name'])));

        return $categories;
    }

    /**
     * Map one public registry entry into the local `RegistryExtension` resource shape.
     *
     * @param array<string, mixed> $extension         entry from `GET /~registry/v1/extensions`
     * @param array<int, string>   $installedPackages npm/composer package names installed locally
     *
     * @return array<string, mixed>
     */
    public static function transform(array $extension, array $installedPackages = [], ?string $registryHost = null): array
    {
        $id       = static::str(Arr::get($extension, 'id', Arr::get($extension, 'public_id', '')));
        $category = static::transformCategory(Arr::get($extension, 'category'));
        $packages = static::packageNames($extension);

        $installed = count(array_intersect($packages, $installedPackages)) > 0;

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
            'name'                     => Arr::get($extension, 'name'),
            'subtitle'                 => Arr::get($extension, 'subtitle'),
            'description'              => Arr::get($extension, 'description'),
            'promotional_text'         => Arr::get($extension, 'promotional_text'),
            'fa_icon'                  => Arr::get($extension, 'fa_icon'),
            'icon_url'                 => Arr::get($extension, 'icon_url'),
            'slug'                     => Arr::get($extension, 'slug'),
            'version'                  => Arr::get($extension, 'version'),
            'status'                   => Arr::get($extension, 'status', 'published'),
            'tags'                     => array_values((array) Arr::get($extension, 'tags', [])),
            'languages'                => array_values((array) Arr::get($extension, 'languages', [])),
            'primary_language'         => Arr::get($extension, 'primary_language'),
            'website_url'              => Arr::get($extension, 'website_url'),
            'repo_url'                 => Arr::get($extension, 'repo_url'),
            'support_url'              => Arr::get($extension, 'support_url'),
            'privacy_policy_url'       => Arr::get($extension, 'privacy_policy_url'),
            'tos_url'                  => Arr::get($extension, 'tos_url'),
            'copyright'                => Arr::get($extension, 'copyright'),
            'payment_required'         => (bool) Arr::get($extension, 'payment_required', false),
            'price'                    => Arr::get($extension, 'price'),
            'sale_price'               => Arr::get($extension, 'sale_price'),
            'on_sale'                  => (bool) Arr::get($extension, 'on_sale', false),
            'currency'                 => Arr::get($extension, 'currency', 'USD'),
            'subscription_required'    => (bool) Arr::get($extension, 'subscription_required', false),
            'core_extension'           => (bool) Arr::get($extension, 'core_extension', false),
            'self_managed'             => (bool) Arr::get($extension, 'self_managed', false),
            'installs_count'           => static::int(Arr::get($extension, 'installs_count', 0)),
            'category_name'            => $category['name'] ?? null,
            'publisher_name'           => Arr::get($extension, 'publisher.name'),
            'current_bundle_version'   => Arr::get($extension, 'current_bundle.version', Arr::get($extension, 'version')),
            'current_bundle_id'        => null,
            'current_bundle_public_id' => null,
            'current_bundle_filename'  => null,
            'next_bundle_id'           => null,
            'next_bundle_public_id'    => null,
            'next_bundle_filename'     => null,
            'category'                 => $category,
            'screenshots'              => [],
            'is_installed'             => $installed,
            'is_purchased'             => false,
            'is_author'                => false,
            'is_installable'           => false,
            'source'                   => static::SOURCE,
            'meta'                     => [
                'source'        => static::SOURCE,
                'registry_host' => $registryHost,
                'packages'      => $packages,
                'publisher'     => Arr::get($extension, 'publisher'),
            ],
            'published_at'             => Arr::get($extension, 'published_at'),
            'created_at'               => Arr::get($extension, 'created_at'),
            'updated_at'               => Arr::get($extension, 'updated_at'),
        ];
    }

    /**
     * Map a public registry category into the `Category` resource shape.
     *
     * @return array<string, mixed>|null
     */
    public static function transformCategory(mixed $category): ?array
    {
        if (!is_array($category)) {
            return null;
        }

        $name = Arr::get($category, 'name');
        $slug = static::str(Arr::get($category, 'slug')) ?: ($name ? Str::slug(static::str($name)) : null);
        $id   = static::str(Arr::get($category, 'id')) ?: ($slug ? 'category_' . $slug : '');

        if ($id === '' || !$name) {
            return null;
        }

        return [
            'id'            => $id,
            'uuid'          => $id,
            'public_id'     => $id,
            'company_uuid'  => null,
            'owner_uuid'    => null,
            'owner_type'    => null,
            'parent_uuid'   => null,
            'name'          => $name,
            'slug'          => $slug,
            'description'   => Arr::get($category, 'description'),
            'icon'          => Arr::get($category, 'icon'),
            'icon_color'    => Arr::get($category, 'icon_color'),
            'icon_url'      => Arr::get($category, 'icon_url'),
            'tags'          => array_values((array) Arr::get($category, 'tags', [])),
            'translations'  => [],
            'meta'          => ['source' => static::SOURCE],
            'for'           => 'extension_category',
            'core_category' => 1,
            'order'         => Arr::get($category, 'order', 0),
        ];
    }

    /**
     * npm and composer package names a registry entry's current bundle declares.
     *
     * @param array<string, mixed> $extension
     *
     * @return array<int, string>
     */
    public static function packageNames(array $extension): array
    {
        $names = [Arr::get($extension, 'package_name')];

        // Keys contain dots (`package.json`), so index the meta array directly instead of using dot notation.
        $meta = Arr::get($extension, 'current_bundle.meta');
        if (is_array($meta)) {
            $names[] = Arr::get($meta['package.json'] ?? [], 'name');
            $names[] = Arr::get($meta['composer.json'] ?? [], 'name');
        }

        return array_values(array_unique(array_filter($names, fn ($name) => is_string($name) && $name !== '')));
    }

    /**
     * Package names of every extension the company has installed locally.
     *
     * @return array<int, string>
     */
    public static function installedPackageNames(?string $companyUuid): array
    {
        if (!$companyUuid) {
            return [];
        }

        $installs = RegistryExtensionInstall::where('company_uuid', $companyUuid)
            ->with(['extension.currentBundle'])
            ->get();

        $names = [];
        foreach ($installs as $install) {
            $bundle = data_get($install, 'extension.currentBundle');
            $meta   = is_object($bundle) ? data_get($bundle, 'meta') : null;
            $meta   = is_array($meta) ? $meta : [];

            $names[] = Arr::get($meta['package.json'] ?? [], 'name');
            $names[] = Arr::get($meta['composer.json'] ?? [], 'name');
            $names[] = data_get($install, 'extension.public_id');
            $names[] = data_get($install, 'extension.slug');
        }

        return array_values(array_unique(array_filter($names, fn ($name) => is_string($name) && $name !== '')));
    }

    /**
     * Coerce a scalar to string; anything else becomes an empty string.
     */
    protected static function str(mixed $value): string
    {
        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }

    /**
     * Coerce a numeric value to int, falling back to a default.
     */
    protected static function int(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<string, mixed> $extension
     */
    protected static function matchesSearch(array $extension, string $search): bool
    {
        $haystack = implode(' ', array_filter([
            Arr::get($extension, 'name'),
            Arr::get($extension, 'subtitle'),
            Arr::get($extension, 'description'),
            Arr::get($extension, 'publisher_name'),
            Arr::get($extension, 'category_name'),
            implode(' ', (array) Arr::get($extension, 'tags', [])),
        ], 'is_string'));

        return Str::contains(Str::lower($haystack), Str::lower($search));
    }

    /**
     * @param array<string, mixed> $extension
     */
    protected static function matchesCategory(array $extension, string $category): bool
    {
        $candidates = array_filter([
            Arr::get($extension, 'category.id'),
            Arr::get($extension, 'category.slug'),
            Arr::get($extension, 'category.name'),
            Arr::get($extension, 'category_uuid'),
        ], 'is_string');

        foreach ($candidates as $candidate) {
            if (strcasecmp($candidate, $category) === 0) {
                return true;
            }
        }

        return false;
    }
}
