<?php

namespace Fleetbase\RegistryBridge\Http\Controllers\Internal\v1;

use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Models\Setting;
use Fleetbase\RegistryBridge\Http\Controllers\RegistryBridgeController;
use Fleetbase\RegistryBridge\Http\Requests\CreateRegistryExtensionRequest;
use Fleetbase\RegistryBridge\Http\Requests\RegistryExtensionActionRequest;
use Fleetbase\RegistryBridge\Http\Resources\PublicRegistryExtension;
use Fleetbase\RegistryBridge\Models\RegistryExtension;
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

        PublicRegistryExtension::wrap('registryExtensions');

        return PublicRegistryExtension::collection($extensions);
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
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection the collection of installed extensions wrapped as a resource
     */
    public function installed()
    {
        $installedExtensions = RegistryExtension::whereHas('installs', function ($query) {
            $query->where('company_uuid', session('company'));
        })->get();

        $this->resource::wrap('registryExtensions');

        return $this->resource::collection($installedExtensions);
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
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection the collection of purchased extensions wrapped as a resource
     */
    public function purchased()
    {
        $purchasedExtensions = RegistryExtension::whereHas('purchases', function ($query) {
            $query->where('company_uuid', session('company'));
        })->get();

        $this->resource::wrap('registryExtensions');

        return $this->resource::collection($purchasedExtensions);
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
     * This method fetches the current registry host and token from the configuration
     * settings or environment variables and returns them in a JSON response.
     *
     * @param AdminRequest $request the incoming HTTP request containing the new host and token
     *
     * @return \Illuminate\Http\JsonResponse a JSON response containing the registry host and token
     */
    public function getConfig(AdminRequest $request)
    {
        $registryHost     = config('registry-bridge.registry.host', env('REGISTRY_HOST', 'https://registry.fleetbase.io'));
        $registryToken    = config('registry-bridge.registry.token', env('REGISTRY_TOKEN'));

        return response()->json([
            'host'  => $registryHost,
            'token' => $registryToken,
        ]);
    }

    /**
     * Save the registry configuration.
     *
     * This method updates the registry host and token based on the provided request input.
     * If no input is provided, it uses the current configuration values or environment variables.
     * The updated configuration is then saved in the settings and returned in a JSON response.
     *
     * @param AdminRequest $request the incoming HTTP request containing the new host and token
     *
     * @return \Illuminate\Http\JsonResponse a JSON response containing the updated registry host and token
     */
    public function saveConfig(AdminRequest $request)
    {
        $currentRegistryHost     = config('registry-bridge.registry.host', env('REGISTRY_HOST', 'https://registry.fleetbase.io'));
        $currentRegistryToken    = config('registry-bridge.registry.token', env('REGISTRY_TOKEN'));
        $registryHost            = $request->input('host', $currentRegistryHost);
        $registryToken           = $request->input('token', $currentRegistryToken);

        // Save values in settings and config
        if ($registryHost) {
            Setting::configure('registry-bridge.registry.host', $registryHost);
            config(['registry-bridge.registry.host' => $registryHost]);
        }

        if ($registryToken) {
            Setting::configure('registry-bridge.registry.token', $registryToken);
            config(['registry-bridge.registry.token' => $registryToken]);
        }

        // Reboot registry auth
        Utils::bootRegistryAuth(true);

        return response()->json([
            'host'  => $registryHost,
            'token' => $registryToken,
        ]);
    }
}
