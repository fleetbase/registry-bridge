<?php

namespace Fleetbase\RegistryBridge\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Resources\Category as CategoryResource;
use Fleetbase\Models\Category;
use Fleetbase\Models\File;
use Fleetbase\RegistryBridge\Models\RegistryExtension;
use Fleetbase\RegistryBridge\Models\RegistryExtensionBundle;
use Fleetbase\RegistryBridge\Models\RegistryUser;
use Fleetbase\RegistryBridge\Support\Utils;
use Illuminate\Http\Request;

class RegistryController extends Controller
{
    /**
     * Retrieve a collection of categories that have registry extensions.
     *
     * This method fetches categories that have associated registry extensions
     * and meet the specified criteria (core_category equals 1 and 'for' equals
     * 'extension_category'). The retrieved categories are then wrapped using
     * the CategoryResource and returned as a collection of CategoryResource.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     *                                                                     A collection of CategoryResource objects representing the categories
     */
    public function categories()
    {
        $categories = Category::whereHas('registryExtensions')->where(['core_category' => 1, 'for' => 'extension_category'])->get();

        CategoryResource::wrap('categories');

        return CategoryResource::collection($categories);
    }

    /**
     * Retrieve a list of installed engines for the current company session.
     *
     * This method fetches registry extensions that have been installed by the
     * company identified in the current session. It disables caching for the
     * query, filters the results based on the company's UUID stored in the session,
     * and maps the retrieved extensions to extract the 'package.json' metadata.
     * The result is returned as a JSON response.
     *
     * @return \Illuminate\Http\JsonResponse
     *                                       A JSON response containing a list of installed engines with their metadata
     */
    public function getInstalledEngines(Request $request)
    {
        if ($request->session()->has('company')) {
            $installedExtensions = RegistryExtension::whereHas('installs', function ($query) {
                $query->where('company_uuid', session('company'));
            })->get()->map(function ($extension) {
                return $extension->currentBundle->meta['package.json'] ?? [];
            });

            return response()->json($installedExtensions);
        }

        return [];
    }

    /**
     * Determines if a specified engine is installed for the authenticated user's company.
     *
     * Retrieves the 'engine' input from the request and checks if the user is authenticated,
     * the session has a 'company', and the 'engine' parameter is provided. It then queries
     * the `RegistryExtension` model to determine if the engine is installed for the company.
     *
     * @param Request $request the incoming HTTP request containing the 'engine' parameter
     *
     * @return array An associative array with the installation status, e.g., ['installed' => true].
     */
    public function getEngineInstallStatus(Request $request)
    {
        $engine = $request->input('engine');

        if ($request->session()->has('company') && $engine) {
            $installed = RegistryExtension::whereHas(
                'currentBundle',
                function ($query) use ($engine) {
                    $query->where('meta->package.json->name', $engine);
                }
            )
            ->whereHas(
                'installs',
                function ($query) {
                    $query->where('company_uuid', session('company'));
                }
            )->exists();

            return ['installed' => $installed];
        }

        return ['installed' => false];
    }

    /**
     * Lookup and retrieve package information based on the provided package name.
     *
     * This method handles a request to lookup a package by its name. It utilizes the `RegistryExtension::lookup` method to find the
     * corresponding registry extension. If no extension is found or if the extension does not have valid package or composer data,
     * an error response is returned.
     *
     * If a valid extension and its associated bundle are found, the function extracts the package and composer names from the
     * `package.json` and `composer.json` metadata. These names are then returned in a JSON response.
     *
     * @param Request $request the incoming HTTP request containing the 'package' input parameter
     *
     * @return \Illuminate\Http\JsonResponse a JSON response containing the package and composer names if found, or an error message otherwise
     */
    public function lookupPackage(Request $request)
    {
        $packageName       = $request->input('package');
        $registryExtension = RegistryExtension::lookup($packageName);
        if (!$registryExtension) {
            return response()->error('No extension found by this name for install');
        }

        if (!$registryExtension->currentBundle) {
            return response()->error('No valid package data found for this extension install');
        }

        $packageJson = $registryExtension->currentBundle->meta['package.json'];
        if (!$packageJson) {
            return response()->error('No valid package data found for this extension install');
        }

        $composerJson = $registryExtension->currentBundle->meta['composer.json'];
        if (!$composerJson) {
            return response()->error('No valid package data found for this extension install');
        }

        $packageJsonName  = data_get($packageJson, 'name');
        $composerJsonName = data_get($composerJson, 'name');

        return response()->json([
            'npm'      => $packageJsonName,
            'composer' => $composerJsonName,
        ]);
    }

    /**
     * Handles the upload of an extension bundle to the registry.
     *
     * This method performs the following operations:
     * - Authenticates the user using a Bearer token from the Authorization header.
     * - Validates the uploaded bundle file (ensuring it's a valid tar.gz file).
     * - Extracts necessary files (`extension.json`, `package.json`, `composer.json`) from the bundle.
     * - Associates the bundle with the correct extension based on package information.
     * - Checks if the user is authorized to upload bundles for the extension.
     * - Uploads the bundle to the storage system.
     * - Creates a file record in the database.
     * - Updates metadata and versioning information.
     * - Creates a new extension bundle record.
     *
     * @param Request $request the HTTP request containing the bundle file and authentication token
     *
     * @return \Illuminate\Http\JsonResponse a JSON response indicating the success or failure of the upload process
     */
    public function bundleUpload(Request $request)
    {
        // Check for Authorization header
        $authHeader = $request->header('Authorization');
        if (!$authHeader) {
            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        // Extract the token from the 'Bearer ' prefix
        $token = null;
        if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];
        }

        // Validate the token (implement your own token validation logic)
        $registryUser = RegistryUser::findFromToken($token);
        if (!$registryUser) {
            return response()->json(['error' => 'Unauthorized.', 'token' => $token], 401);
        }

        // Check if file was uploaded
        if (!$request->hasFile('bundle')) {
            return response()->json(['error' => 'No bundle uploaded.'], 400);
        }

        $bundle = $request->file('bundle');

        // Validate the file
        if (!$bundle->isValid()) {
            return response()->json(['error' => 'Invalid bundle file uploaded.'], 400);
        }

        // Ensure the file is a tar.gz
        $mimeType = $bundle->getMimeType();
        if ($mimeType !== 'application/gzip' && $mimeType !== 'application/x-gzip') {
            return response()->json(['error' => 'Invalid bundle file type.'], 400);
        }

        // Get the extension assosciated to bundle by extension name
        try {
            $bundleData = RegistryExtensionBundle::extractUploadedBundleFile($bundle, ['extension.json', 'package.json', 'composer.json']);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        $bundlePackageData = Utils::getObjectKeyValue($bundleData, 'package.json') ?? Utils::getObjectKeyValue($bundleData, 'composer.json');
        if ($bundlePackageData && data_get($bundlePackageData, 'name')) {
            $extension = RegistryExtension::findByPackageName(data_get($bundlePackageData, 'name'));
            if (!$extension) {
                return response()->json(['error' => 'Unable to find extension for the uploaded bundle.'], 400);
            }

            if (!$extension->isOwnedBy($registryUser)) {
                return response()->json(['error' => 'User is not authorized to upload bundles for this extension.'], 401);
            }
        } else {
            return response()->json(['error' => 'Unable to parse uploaded bundle.'], 400);
        }

        // Prepare to upload the bundle
        $size        = $bundle->getSize();
        $fileName    = File::randomFileNameFromRequest($request, 'bundle');
        $disk        = config('filesystems.default');
        $bucket      = config('filesystems.disks.' . $disk . '.bucket', config('filesystems.disks.s3.bucket'));
        $path        = 'uploads/extensions/' . $extension->uuid . '/bundles';
        $type        = 'extension_bundle';

        // Upload the bundle
        try {
            $path = $bundle->storeAs($path, $fileName, ['disk' => $disk]);
        } catch (\Throwable $e) {
            return response()->error($e->getMessage());
        }

        // If file upload failed
        if ($path === false) {
            return response()->error('File upload failed.');
        }

        // Create a file record
        try {
            $file = File::createFromUpload($request->file('bundle'), $path, $type, $size, $disk, $bucket);
        } catch (\Throwable $e) {
            return response()->error($e->getMessage());
        }

        // Set company and uploader (developer accounts have neither)
        $fileAttributes = ['uploader_uuid' => $registryUser->user_uuid];
        if ($registryUser->company_uuid) {
            $fileAttributes['company_uuid'] = $registryUser->company_uuid;
        }
        $file->update($fileAttributes);

        // Set file subject to extension
        $file = $file->setSubject($extension);

        // Get extension.json contents
        $extensionJson = Utils::getObjectKeyValue($bundleData, 'extension.json');
        if (!$extensionJson) {
            return response()->error('Unable to find `extension.json` file required in bundle.');
        }

        // Set version in file meta
        $file->updateMeta('version', data_get($extensionJson, 'version'));

        // Check if version is set
        if (!isset($extensionJson->version)) {
            return response()->error('No `version` set in the `extension.json`');
        }

        // Check if either api or engine property is set
        if (!isset($extensionJson->engine) && !isset($extensionJson->api)) {
            return response()->error('No `api` or `engine` property set in the `extension.json`');
        }

        // Set bundle number to parsed JSON
        $extensionJson->bundle_number = RegistryExtensionBundle::getNextBundleNumber($extension);

        // Create the bundle (company only when the publisher is a cloud account)
        $bundleAttributes = [
            'created_by_uuid' => $registryUser->user_uuid,
            'extension_uuid'  => $extension->uuid,
            'bundle_uuid'     => $file->uuid,
            'status'          => 'pending',
        ];
        if ($registryUser->company_uuid) {
            $bundleAttributes['company_uuid'] = $registryUser->company_uuid;
        }
        $extensionBundle = RegistryExtensionBundle::create($bundleAttributes);

        $extensionBundle->update(['bundle_number' => $extensionJson->bundle_number, 'version' => $extensionJson->version]);
        $extensionBundle->updateMetaProperties((array) $bundleData);

        return response()->json(['message' => 'Bundle uploaded successfully', 'filename' => $fileName, 'bundle' => $extensionBundle, 'extension' => $extension], 200);
    }
}
