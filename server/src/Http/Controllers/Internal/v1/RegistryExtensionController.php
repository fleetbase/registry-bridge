<?php

namespace Fleetbase\RegistryBridge\Http\Controllers\Internal\v1;

use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Models\Setting;
use Fleetbase\RegistryBridge\Exceptions\RegistryException;
use Fleetbase\RegistryBridge\Exceptions\RegistryServiceException;
use Fleetbase\RegistryBridge\Http\Controllers\RegistryBridgeController;
use Fleetbase\RegistryBridge\Http\Requests\CreateRegistryExtensionRequest;
use Fleetbase\RegistryBridge\Http\Requests\RegistryExtensionActionRequest;
use Fleetbase\RegistryBridge\Http\Resources\PublicRegistryExtension;
use Fleetbase\RegistryBridge\Http\Resources\RemoteRegistryExtension;
use Fleetbase\RegistryBridge\Models\RegistryExtension;
use Fleetbase\RegistryBridge\Models\RegistryServiceInstall;
use Fleetbase\RegistryBridge\Support\CentralCatalog;
use Fleetbase\RegistryBridge\Support\InstanceRegistration;
use Fleetbase\RegistryBridge\Support\RegistryClient;
use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Fleetbase\RegistryBridge\Support\ServiceCatalog;
use Fleetbase\RegistryBridge\Support\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class RegistryExtensionController extends RegistryBridgeController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'registry_extension';

    /**
     * Display a public list of all published extensions.
     *
     * This endpoint is publicly accessible and returns all extensions with a status of 'published'.
     * The results are cached for 15 minutes to improve performance and reduce database load.
     * This endpoint is designed to be called by self-hosted instances to discover available extensions.
     *
     * @param Request $request the incoming HTTP request
     *
     * @return \Illuminate\Http\JsonResponse the collection of published extensions
     */
    public function listPublicExtensions(Request $request)
    {
        $cacheKey = 'public-extensions-list';
        $cacheTtl = now()->addMinutes(15);

        $extensions = \Illuminate\Support\Facades\Cache::remember($cacheKey, $cacheTtl, function () {
            return RegistryExtension::where('status', 'published')
                ->with(['company', 'category', 'currentBundle'])
                ->withCount('installs')
                ->orderBy('installs_count', 'desc')
                ->get();
        });

        PublicRegistryExtension::withoutWrapping();

        return PublicRegistryExtension::collection($extensions);
    }

    /**
     * Query registry extensions.
     *
     * The Explore listing (`?explore=1`) is served from the Registry Service when one is configured,
     * else from the cloud registry catalog unless this instance is configured as a self-hosted registry
     * (`registry-bridge.registry.self_hosted`), in which case it falls back to the local
     * `registry_extensions` table.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Http\JsonResponse|\Illuminate\Http\Resources\Json\JsonResource
     */
    public function queryRecord(Request $request)
    {
        if ($request->boolean('explore') && CentralCatalog::enabled()) {
            try {
                $extensions = CentralCatalog::query($request);
            } catch (RegistryException $e) {
                return response()->error($e->getMessage(), 503);
            }

            RemoteRegistryExtension::wrap($this->resourcePluralName);

            return RemoteRegistryExtension::collection($extensions);
        }

        return parent::queryRecord($request);
    }

    /**
     * Creates a record with request payload.
     *
     * @return \Illuminate\Http\Response
     */
    public function createRecord(Request $request)
    {
        // Create validation request
        $createRegistryExtensionRequest  = CreateRegistryExtensionRequest::createFrom($request);
        $rules                           = $createRegistryExtensionRequest->rules();

        // Manually validate request
        $validator = Validator::make($request->input('registryExtension'), $rules);
        if ($validator->fails()) {
            return $createRegistryExtensionRequest->responseWithErrors($validator);
        }

        try {
            $record = $this->model->createRecordFromRequest($request);

            return ['registryExtension' => new $this->resource($record)];
        } catch (\Throwable $e) {
            return response()->error($e->getMessage());
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->error($e->getMessage());
        } catch (FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
        }
    }

    /**
     * Display a list of installed extensions for the current company.
     *
     * This function retrieves all extensions that are installed for the company
     * identified by the `company_uuid` stored in the session. It disables the cache
     * for the `RegistryExtension` model to ensure fresh data is fetched from the database.
     *
     * The extensions are filtered based on their association with any installation
     * record that matches the `company_uuid` from the session. The resulting collection
     * of installed extensions is then wrapped and returned as a resource collection.
     *
     * With a Registry Service configured, extensions installed from the service are added, mapped from
     * the instance API (`installed_version`, `latest_version`, `update_available`) and the catalog.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Http\JsonResponse the collection of installed extensions wrapped as a resource
     */
    public function installed(?Request $request = null)
    {
        $request ??= request();

        $installedExtensions = RegistryExtension::whereHas('installs', function ($query) {
            $query->where('company_uuid', session('company'));
        })->get();

        $this->resource::wrap('registryExtensions');

        $config = RegistryConfig::current();
        if (!$config->usesService()) {
            return $this->resource::collection($installedExtensions);
        }

        $localInstalls = RegistryServiceInstall::where('company_uuid', session('company'))->get()->map(fn ($install) => $install->toLocalInstall())->all();

        try {
            $serviceExtensions = ServiceCatalog::installed(RegistryClient::fromConfig($config), $config, $localInstalls, CentralCatalog::installedPackageNames(session('company')));
        } catch (RegistryException $e) {
            return response()->error($e->getMessage(), 503);
        }

        return response()->json([
            'registryExtensions' => array_merge($serviceExtensions, $this->resource::collection($installedExtensions)->resolve($request)),
        ]);
    }

    /**
     * Display a list of purchased extensions for the current company.
     *
     * This function retrieves all extensions that are purchased for the company
     * identified by the `company_uuid` stored in the session. It disables the cache
     * for the `RegistryExtension` model to ensure fresh data is fetched from the database.
     *
     * The extensions are filtered based on their association with any purchase
     * record that matches the `company_uuid` from the session. The resulting collection
     * of purchased extensions is then wrapped and returned as a resource collection.
     *
     * With a Registry Service configured, extensions the instance's organization holds an active paid
     * licence for are added.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Http\JsonResponse the collection of purchased extensions wrapped as a resource
     */
    public function purchased(?Request $request = null)
    {
        $request ??= request();

        $purchasedExtensions = RegistryExtension::whereHas('purchases', function ($query) {
            $query->where('company_uuid', session('company'));
        })->get();

        $this->resource::wrap('registryExtensions');

        $config = RegistryConfig::current();
        if (!$config->usesService()) {
            return $this->resource::collection($purchasedExtensions);
        }

        try {
            $serviceExtensions = ServiceCatalog::purchased(RegistryClient::fromConfig($config), $config, CentralCatalog::installedPackageNames(session('company')));
        } catch (RegistryException $e) {
            return response()->error($e->getMessage(), 503);
        }

        return response()->json([
            'registryExtensions' => array_merge($serviceExtensions, $this->resource::collection($purchasedExtensions)->resolve($request)),
        ]);
    }

    /**
     * Retrieve analytics for a specific registry extension.
     *
     * This method fetches analytics for a registry extension identified by
     * the provided ID. It gathers various metrics, including the number of
     * installs, uninstalls, purchases, and the total purchase amount. If the
     * extension cannot be found, it returns an error response. Otherwise, it
     * returns the collected metrics as a JSON response.
     *
     * @param Request $request
     *                         The incoming request instance containing the extension ID
     *
     * @return \Illuminate\Http\JsonResponse
     *                                       A JSON response containing the collected analytics metrics or an error message
     */
    public function analytics(Request $request)
    {
        $id        = $request->input('id');
        $extension = RegistryExtension::find($id);
        if (!$extension) {
            return response()->error('Unable to find extension to fetch analytics.');
        }

        $metrics = [];

        // Get number of installs
        $metrics['install_count'] = $extension->installs()->count();

        // Get number of uninstalls
        $metrics['uninstall_count'] = $extension->installs()->withoutGlobalScopes()->whereNotNull('deleted_at')->count();

        // Get number of purchases
        $metrics['purchase_count'] = $extension->purchases()->count();

        // Get total amount in purchases
        $totalPurchaseAmount        = $extension->purchases()->get()->sum('locked_price');
        $metrics['purchase_amount'] = Utils::moneyFormat($totalPurchaseAmount, $extension->currency);

        // Respond with metrics
        return response()->json($metrics);
    }

    /**
     * Approves a specific extension by its ID.
     *
     * This function locates a `RegistryExtension` using the provided ID and sets its status to 'approved'.
     * If the extension is successfully found and updated, it returns the extension resource. If the extension
     * cannot be found, it returns an error response indicating the inability to locate the extension.
     *
     * @param RegistryExtensionActionRequest $request the validated request object
     *
     * @return \Illuminate\Http\Response|array returns an array containing the extension resource if successful,
     *                                         or an error response if the extension cannot be found
     */
    public function approve(RegistryExtensionActionRequest $request)
    {
        $id        = $request->input('id');
        $extension = RegistryExtension::find($id);
        if ($extension) {
            $extension->update(['status' => 'approved', 'current_bundle_uuid' => $extension->next_bundle_uuid]);
            $extension->nextBundle()->update(['status' => 'approved']);
        } else {
            return response()->error('Unable to find extension for approval.');
        }

        return ['registryExtension' => new $this->resource($extension)];
    }

    /**
     * Mannually publishes a specific extension by its ID.
     *
     * This function locates a `RegistryExtension` using the provided ID and sets its status to 'published'.
     * If the extension is successfully found and updated, it returns the extension resource. If the extension
     * cannot be found, it returns an error response indicating the inability to locate the extension.
     *
     * @param RegistryExtensionActionRequest $request the validated request object
     *
     * @return \Illuminate\Http\Response|array returns an array containing the extension resource if successful,
     *                                         or an error response if the extension cannot be found
     */
    public function manualPublish(RegistryExtensionActionRequest $request)
    {
        $id        = $request->input('id');
        $extension = RegistryExtension::find($id);
        if ($extension) {
            $extension->update(['status' => 'published', 'current_bundle_uuid' => $extension->next_bundle_uuid]);
            $extension->nextBundle()->update(['status' => 'published']);
        } else {
            return response()->error('Unable to find extension to publish.');
        }

        return ['registryExtension' => new $this->resource($extension)];
    }

    /**
     * Rejects a specific extension by its ID.
     *
     * Locates a `RegistryExtension` using the provided ID and updates its status to 'rejected'. It also
     * intends to send a rejection reason via email to the extension's author (as indicated by commented code).
     * If the extension is found and updated, it returns the extension resource. If not found, it returns an
     * error response indicating the extension could not be located.
     *
     * Note: This method assumes the rejection reason is handled separately (possibly by another request).
     *
     * @param RegistryExtensionActionRequest $request the validated request object
     *
     * @return \Illuminate\Http\Response|array returns an array containing the extension resource if successful,
     *                                         or an error response if the extension cannot be found
     */
    public function reject(RegistryExtensionActionRequest $request)
    {
        $id        = $request->input('id');
        $extension = RegistryExtension::find($id);
        if ($extension) {
            $extension->update(['status' => 'rejected']);
            $extension->nextBundle()->update(['status' => 'rejected']);
        } else {
            return response()->error('Unable to find extension for rejection.');
        }

        // send rejection reason via email to extension author
        // $reason = $request->input('reason');

        return ['registryExtension' => new $this->resource($extension)];
    }

    /**
     * Submits an extension for review based on its ID.
     *
     * This method attempts to submit a `RegistryExtension` for review. It first checks
     * if the extension is ready for submission by calling the static method
     * `isExtensionReadyForSubmission`. If the extension is not ready, it returns an error response.
     * If the extension is ready, it updates the extension's status to 'awaiting_review' and returns
     * a JSON response indicating success.
     *
     * @param string $id the unique identifier of the extension to be submitted
     *
     * @return \Illuminate\Http\JsonResponse returns a JSON response indicating the outcome of the operation
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException if no extension with the given ID is found
     */
    public function submit(string $id)
    {
        $isReady = RegistryExtension::isExtensionReadyForSubmission($id);
        if (!$isReady) {
            return response()->error('Unable to submit extension for review.');
        }

        $extension = RegistryExtension::find($id);
        if ($extension) {
            $extension->update(['status' => 'awaiting_review']);
        }

        return ['registryExtension' => new $this->resource($extension)];
    }

    /**
     * Handles the download request for an extension bundle.
     *
     * This function retrieves a specific `RegistryExtension` by its ID and attempts to download
     * its latest bundle. If the extension exists and has an associated bundle, it returns a download response
     * with the appropriate file. If the extension doesn't exist or doesn't have a bundle, it returns an error response.
     *
     * @param RegistryExtensionActionRequest $request the validated request object
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\Response
     *                                                                                        Returns a download response for the bundle if successful, or an error response if not
     */
    public function downloadBundle(RegistryExtensionActionRequest $request)
    {
        $id        = $request->input('id');
        $extension = RegistryExtension::find($id);
        if ($extension && $extension->nextBundle) {
            $bundleFile = data_get($extension, 'nextBundle.bundle');
            if ($bundleFile) {
                return Storage::disk($bundleFile->disk)->download($bundleFile->path, $bundleFile->name);
            }
        }

        return response()->error('Failed to download extension bundle');
    }

    /**
     * Retrieve the current registry configuration.
     *
     * Returns the legacy registry host and token, the Registry Service URL, the API version in use and
     * this instance's registration (the instance token itself is never returned, only a masked hint).
     *
     * @param AdminRequest $request the incoming HTTP request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getConfig(AdminRequest $request)
    {
        return response()->json($this->configPayload(RegistryConfig::current()));
    }

    /**
     * Save the registry configuration.
     *
     * Updates the legacy registry host and token and the Registry Service URL and API version. Values
     * left out of the request keep their current setting; an empty `service_url` clears it. Pointing
     * the instance at a different service forgets its registration, since the instance token belongs
     * to the service that issued it.
     *
     * @param AdminRequest $request the incoming HTTP request containing `host`, `token`, `service_url`, `api_version`
     *
     * @return \Illuminate\Http\JsonResponse the saved configuration (see `getConfig()`)
     */
    public function saveConfig(AdminRequest $request)
    {
        $current       = RegistryConfig::current();
        $registryHost  = $request->input('host', $current->host());
        $registryToken = $request->input('token', $current->token());

        // Save values in settings and config
        if ($registryHost) {
            Setting::configure('registry-bridge.registry.host', $registryHost);
            config(['registry-bridge.registry.host' => $registryHost]);
        }

        if ($registryToken) {
            Setting::configure('registry-bridge.registry.token', $registryToken);
            config(['registry-bridge.registry.token' => $registryToken]);
        }

        if ($request->exists('service_url')) {
            $serviceUrl = trim((string) $request->input('service_url'));
            $serviceUrl = $serviceUrl !== '' ? RegistryConfig::normalizeUrl($serviceUrl) : null;

            if ($serviceUrl !== $current->serviceUrl() && $current->isRegistered()) {
                InstanceRegistration::forget();
            }

            Setting::configure(InstanceRegistration::SETTING_SERVICE_URL, $serviceUrl);
            config(['registry-bridge.registry.service_url' => $serviceUrl]);
        }

        if ($request->exists('api_version')) {
            $apiVersion = $request->input('api_version');
            $apiVersion = in_array($apiVersion, [RegistryConfig::V1, RegistryConfig::V2], true) ? $apiVersion : null;

            Setting::configure(InstanceRegistration::SETTING_API_VERSION, $apiVersion);
            config(['registry-bridge.registry.api_version' => $apiVersion]);
        }

        // Reboot legacy registry auth; installs from the Registry Service never write credentials to disk
        if (!RegistryConfig::current()->usesService()) {
            Utils::bootRegistryAuth(true);
        }

        // Drop any catalog cached for the previous host/token
        RegistryClient::flush();

        return response()->json($this->configPayload(RegistryConfig::current()));
    }

    /**
     * Test the connection to the configured registry.
     *
     * Calls the registry's catalog endpoint with the saved settings (or the values supplied in the
     * request, so unsaved form values can be tried) and reports whether it answered. Against a Registry
     * Service the saved instance token is also checked, as long as the URL tested is the saved one.
     *
     * @param AdminRequest $request optional `host`, `token`, `service_url` overrides
     *
     * @return \Illuminate\Http\JsonResponse `{ok, latency_ms, status, extension_count, host, error, api_version, instance}`
     */
    public function testConfig(AdminRequest $request)
    {
        $config     = RegistryConfig::current();
        $serviceUrl = trim((string) $request->input('service_url', ''));

        if ($serviceUrl !== '' || ($config->usesService() && !$request->filled('host'))) {
            $serviceUrl = $serviceUrl !== '' ? RegistryConfig::normalizeUrl($serviceUrl) : (string) $config->serviceUrl();
            $token      = $serviceUrl === $config->serviceUrl() ? $config->instanceToken() : null;
            $client     = new RegistryClient($serviceUrl, $token, RegistryConfig::V2, $config);

            return response()->json($client->testConnection());
        }

        $host  = $request->input('host');
        $token = $request->input('token');

        $client = new RegistryClient(
            is_string($host) && trim($host) !== '' ? trim($host) : null,
            is_string($token) && trim($token) !== '' ? trim($token) : null,
            RegistryConfig::V1,
            $config
        );

        return response()->json($client->testConnection());
    }

    /**
     * Register this instance with the Registry Service.
     *
     * Exchanges a one-time registration code (minted by an organization owner or admin on the service)
     * for an instance token via `POST /v1/instances/register`, reporting this instance's name, host,
     * kind and console and API versions. The token is stored encrypted in settings and never returned.
     *
     * An instance that is already registered sends its current token, and the service matches it by that token or
     * by its host, so registering again rotates the same instance (same id, installs and licence seats) and
     * `instance.rotated` is true in the response.
     *
     * @param AdminRequest $request `code` (required), optional `service_url`, `name`, `host`, `console_version`
     *
     * @return \Illuminate\Http\JsonResponse the configuration (see `getConfig()`)
     */
    public function registerInstance(AdminRequest $request)
    {
        $code = trim((string) $request->input('code', ''));
        if ($code === '') {
            return response()->error('Enter the registration code from your registry organization.', 422);
        }

        $config     = RegistryConfig::current();
        $serviceUrl = trim((string) $request->input('service_url', ''));
        $serviceUrl = $serviceUrl !== '' ? RegistryConfig::normalizeUrl($serviceUrl) : $config->serviceUrl();
        if (!$serviceUrl) {
            return response()->error('Set the Registry Service URL before registering this instance.', 422);
        }

        $payload = InstanceRegistration::payload(
            $code,
            (string) ($request->input('name') ?: config('app.name', 'Fleetbase')),
            $request->input('host') ?: config('app.url'),
            $config->instanceKind(),
            $request->input('console_version') ?: InstanceRegistration::detectConsoleVersion(config('fleetbase.console.path')),
            InstanceRegistration::detectApiVersion(),
            InstanceRegistration::previousToken($config, $serviceUrl)
        );

        try {
            $registration = (new RegistryClient($serviceUrl, null, RegistryConfig::V2, $config))->registerInstance($payload);
        } catch (RegistryServiceException $e) {
            return response()->error($e->getMessage(), $e->status() >= 400 && $e->status() < 500 ? 422 : 502);
        } catch (RegistryException $e) {
            return response()->error($e->getMessage(), 503);
        }

        InstanceRegistration::store($registration, $serviceUrl);

        return response()->json($this->configPayload(RegistryConfig::current()));
    }

    /**
     * Forget this instance's registration (instance token and details) on this side.
     *
     * The service has no self-revoke endpoint yet; an organization admin revokes the token there.
     *
     * @return \Illuminate\Http\JsonResponse the configuration (see `getConfig()`)
     */
    public function unregisterInstance(AdminRequest $request)
    {
        InstanceRegistration::forget();

        return response()->json($this->configPayload(RegistryConfig::current()));
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
            'host'          => $config->host(),
            'token'         => $config->token(),
            'service_url'   => $config->serviceUrl(),
            'api_version'   => $config->apiVersion(),
            'instance_kind' => $config->instanceKind(),
            'instance'      => [
                'registered'    => $config->isRegistered(),
                'id'            => $instance['id'] ?? null,
                'name'          => $instance['name'] ?? null,
                'organization'  => $instance['organization'] ?? null,
                'token_hint'    => $config->isRegistered() ? InstanceRegistration::maskToken($config->instanceToken()) : null,
                'registered_at' => $instance['registered_at'] ?? null,
                'rotated'       => ($instance['rotated'] ?? false) === true,
            ],
        ];
    }
}
