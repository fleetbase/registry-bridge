<?php

namespace Fleetbase\RegistryBridge\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Models\Setting;
use Fleetbase\RegistryBridge\Exceptions\RegistryException;
use Fleetbase\RegistryBridge\Exceptions\RegistryServiceException;
use Fleetbase\RegistryBridge\Models\RegistryServiceInstall;
use Fleetbase\RegistryBridge\Support\InstanceRegistration;
use Fleetbase\RegistryBridge\Support\RegistryClient;
use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Fleetbase\RegistryBridge\Support\ServiceCatalog;
use Fleetbase\RegistryBridge\Support\ServiceCheckout;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The Registry Service catalog and this instance's place in it, for the console's Extensions module.
 */
class RegistryExtensionController extends Controller
{
    /**
     * One page of Explore: `query`, `category`, `pricing`, `hosting`, `hide_installed`, `sort`, `page`, `limit`.
     *
     * @return \Illuminate\Http\JsonResponse `{registryExtensions, meta}`
     */
    public function query(Request $request)
    {
        $config = RegistryConfig::current();

        try {
            $page = ServiceCatalog::query($request, RegistryClient::fromConfig($config), $config, RegistryServiceInstall::packageNames(session('company')));
        } catch (RegistryException $e) {
            return response()->error($e->getMessage(), 503);
        }

        return response()->json(['registryExtensions' => $page->items(), 'meta' => static::paginationMeta($page)]);
    }

    /**
     * One listing by `ext_` id or slug, decorated with this instance's install and licence state.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function find(string $id)
    {
        $config = RegistryConfig::current();

        try {
            $extension = ServiceCatalog::find($id, RegistryClient::fromConfig($config), $config, RegistryServiceInstall::packageNames(session('company')));
        } catch (RegistryException $e) {
            return response()->error($e->getMessage(), 503);
        }

        if ($extension === null) {
            return response()->error('Extension not found.', 404);
        }

        return response()->json(['registryExtension' => $extension]);
    }

    /**
     * Published and yanked releases of a listing for the Versions tab.
     *
     * @return \Illuminate\Http\JsonResponse `{latest, channels, releases}`
     */
    public function releases(Request $request, string $id)
    {
        $installed = $request->input('installed_version');

        try {
            $releases = ServiceCatalog::releases($id, RegistryClient::fromConfig(RegistryConfig::current()), is_string($installed) && $installed !== '' ? $installed : null);
        } catch (RegistryException $e) {
            return response()->error($e->getMessage(), 503);
        }

        if ($releases === null) {
            return response()->error('Extension not found.', 404);
        }

        return response()->json($releases);
    }

    /**
     * What every console user needs to know about the registry: the service URL, whether this instance is
     * registered, its kind, the registry organization that owns its licences, the publisher portal and whether
     * paid extensions can be bought here. No secrets.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function context()
    {
        $config = RegistryConfig::current();

        return response()->json([
            'service_url'        => $config->serviceUrl(),
            'registered'         => $config->isRegistered(),
            'instance_kind'      => $config->instanceKind(),
            'organization'       => $config->organization(),
            'publisher_url'      => $config->publisherUrl(),
            'checkout_available' => ServiceCheckout::available($config),
        ]);
    }

    /**
     * Sidebar counts: installed extensions, updates available and purchased (active paid) licences.
     *
     * @return \Illuminate\Http\JsonResponse `{installed, updates, purchased}`
     */
    public function counts()
    {
        $config   = RegistryConfig::current();
        $localIds = RegistryServiceInstall::where('company_uuid', session('company'))->pluck('extension_id')->filter()->values()->all();
        $state    = ServiceCatalog::state(RegistryClient::fromConfig($config), $config);

        return response()->json(ServiceCatalog::counts($state, $localIds));
    }

    /**
     * Extensions installed on this instance: what the service reports in `updates` plus local install records,
     * with installed and latest versions.
     *
     * @return \Illuminate\Http\JsonResponse `{registryExtensions}`
     */
    public function installed()
    {
        $config        = RegistryConfig::current();
        $localInstalls = RegistryServiceInstall::where('company_uuid', session('company'))->get()->map(fn ($install) => $install->toLocalInstall())->all();

        try {
            $extensions = ServiceCatalog::installed(RegistryClient::fromConfig($config), $config, $localInstalls, RegistryServiceInstall::packageNames(session('company')));
        } catch (RegistryException $e) {
            return response()->error($e->getMessage(), 503);
        }

        return response()->json(['registryExtensions' => $extensions]);
    }

    /**
     * Extensions the instance's organization bought: active paid licences, and refunded or revoked ones with
     * their state and receipt. Only active ones entitle installs.
     *
     * @return \Illuminate\Http\JsonResponse `{registryExtensions}`
     */
    public function purchased()
    {
        $config = RegistryConfig::current();

        try {
            $extensions = ServiceCatalog::purchased(RegistryClient::fromConfig($config), $config, RegistryServiceInstall::packageNames(session('company')));
        } catch (RegistryException $e) {
            return response()->error($e->getMessage(), 503);
        }

        return response()->json(['registryExtensions' => $extensions]);
    }

    /**
     * The registry configuration for the admin page.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getConfig(AdminRequest $request)
    {
        return response()->json($this->configPayload(RegistryConfig::current()));
    }

    /**
     * Save the Registry Service URL. Pointing the instance at a different service forgets its registration,
     * since the instance token belongs to the service that issued it.
     *
     * @return \Illuminate\Http\JsonResponse the saved configuration (see `getConfig()`)
     */
    public function saveConfig(AdminRequest $request)
    {
        $current = RegistryConfig::current();

        if ($request->exists('service_url')) {
            $serviceUrl = trim((string) $request->input('service_url'));
            $serviceUrl = $serviceUrl !== '' ? RegistryConfig::normalizeUrl($serviceUrl) : null;

            if ($serviceUrl !== $current->serviceUrl() && $current->isRegistered()) {
                InstanceRegistration::forget();
            }

            Setting::configure(InstanceRegistration::SETTING_SERVICE_URL, $serviceUrl);
            config(['registry-bridge.registry.service_url' => $serviceUrl]);
        }

        // Drop any catalog cached for the previous service
        RegistryClient::flush();

        return response()->json($this->configPayload(RegistryConfig::current()));
    }

    /**
     * Call the Registry Service once, uncached: the saved URL, or `service_url` from the request so an unsaved
     * value can be tried. The saved instance token is checked too when the URL tested is the saved one.
     *
     * @return \Illuminate\Http\JsonResponse `{ok, latency_ms, status, extension_count, host, error, api_version, instance}`
     */
    public function testConfig(AdminRequest $request)
    {
        $config     = RegistryConfig::current();
        $serviceUrl = trim((string) $request->input('service_url', ''));
        $serviceUrl = $serviceUrl !== '' ? RegistryConfig::normalizeUrl($serviceUrl) : (string) ($config->serviceUrl() ?? RegistryConfig::DEFAULT_SERVICE_URL);
        $token      = $serviceUrl === $config->serviceUrl() ? $config->instanceToken() : null;

        return response()->json((new RegistryClient($serviceUrl, $token, $config))->testConnection());
    }

    /**
     * Register this instance with the Registry Service: exchanges a one-time registration code (minted by an
     * organization owner or admin on the service) for an instance token, stored encrypted and never returned.
     *
     * @return \Illuminate\Http\JsonResponse the configuration (see `getConfig()`)
     */
    public function registerInstance(AdminRequest $request)
    {
        try {
            InstanceRegistration::register(
                (string) $request->input('code', ''),
                (string) $request->input('service_url', ''),
                (string) $request->input('name', ''),
                (string) $request->input('host', ''),
                (string) $request->input('console_version', '')
            );
        } catch (RegistryServiceException $e) {
            return response()->error($e->getMessage(), $e->status() >= 400 && $e->status() < 500 ? 422 : 502);
        } catch (RegistryException $e) {
            return response()->error($e->getMessage(), 503);
        }

        return response()->json($this->configPayload(RegistryConfig::current()));
    }

    /**
     * Forget this instance's registration (instance token and details) on this side.
     *
     * @return \Illuminate\Http\JsonResponse the configuration (see `getConfig()`)
     */
    public function unregisterInstance(AdminRequest $request)
    {
        InstanceRegistration::forget();

        return response()->json($this->configPayload(RegistryConfig::current()));
    }

    /**
     * Pagination meta in the shape the console's `Pagination` component reads.
     *
     * @param LengthAwarePaginator<array<string, mixed>> $page
     *
     * @return array<string, int|null>
     */
    public static function paginationMeta(LengthAwarePaginator $page): array
    {
        return [
            'total'        => $page->total(),
            'per_page'     => $page->perPage(),
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
            'from'         => $page->firstItem(),
            'to'           => $page->lastItem(),
        ];
    }

    /**
     * Configuration as returned to the admin page.
     *
     * @return array<string, mixed>
     */
    protected function configPayload(RegistryConfig $config): array
    {
        $instance = $config->instance();

        return [
            'service_url'   => $config->serviceUrl(),
            'publisher_url' => $config->publisherUrl(),
            'instance_kind' => $config->instanceKind(),
            'instance'      => [
                'registered'    => $config->isRegistered(),
                'id'            => $instance['id'] ?? null,
                'name'          => $instance['name'] ?? null,
                'organization'  => $instance['organization'] ?? null,
                'token_hint'    => $config->isRegistered() ? InstanceRegistration::maskToken($config->instanceToken()) : null,
                'registered_at' => $instance['registered_at'] ?? null,
            ],
        ];
    }
}
