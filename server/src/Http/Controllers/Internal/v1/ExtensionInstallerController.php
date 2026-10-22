<?php

namespace Fleetbase\RegistryBridge\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\RegistryBridge\Http\Requests\InstallExtensionRequest;
use Fleetbase\RegistryBridge\Models\RegistryExtension;
use Fleetbase\RegistryBridge\Models\RegistryExtensionInstall;
use Fleetbase\RegistryBridge\Support\CentralCatalog;

class ExtensionInstallerController extends Controller
{
    /**
     * Installs a specified extension for a company.
     *
     * This method handles the installation process of an extension. It first looks up
     * the extension in the `RegistryExtension` model using its public identifier provided
     * in the request. Then, it ensures that the installation is recorded in the
     * `RegistryExtensionInstall` model, linking the extension to the current company.
     * If the installation record already exists, it does not create a duplicate.
     *
     * @param InstallExtensionRequest $request The request object containing the extension
     *                                         identifier. Expected to have an 'extension'
     *                                         field with the public ID of the extension.
     *
     * @return \Illuminate\Http\JsonResponse Returns a JSON response indicating the status.
     *                                       The response contains a 'status' key with the
     *                                       value 'ok' if the process is successful.
     *
     * @throws \Exception throws an exception if the specified extension is not found in the
     *                    `RegistryExtension` model, leading to a potential error when
     *                    trying to access properties of a non-object (`$extension->uuid`)
     */
    public function install(InstallExtensionRequest $request)
    {
        set_time_limit(120 * 6);
        $extension = RegistryExtension::where('public_id', $request->input('extension'))->first();
        if (!$extension) {
            return $this->extensionNotFound($request->input('extension'), 'installed');
        }

        // Check if already installed
        $installed = RegistryExtensionInstall::where(['company_uuid' => session('company'), 'extension_uuid' => $extension->uuid])->exists();
        if ($installed) {
            return response()->error('This extension is already installed.');
        }

        // Check if extensions are pre-installed to system
        $PREINSTALLED_EXTENSIONS = config('registry-bridge.extensions.preinstalled') === true;

        // Run installers if extension needs to be installed
        if ($PREINSTALLED_EXTENSIONS === false) {
            // Install for Composer
            try {
                $extension->currentBundle->installComposerPackage();
            } catch (\Throwable $e) {
                return response()->error($e->getMessage());
            }

            // Install for Console
            try {
                $extension->currentBundle->installEnginePackage();
            } catch (\Throwable $e) {
                return response()->error($e->getMessage());
            }

            // Rebuild Console
            try {
                $extension->currentBundle->buildConsole();
            } catch (\Throwable $e) {
                return response()->error($e->getMessage());
            }
        }

        // Run installer progress if preinstalled
        if ($PREINSTALLED_EXTENSIONS === true) {
            $extension->currentBundle->runInstallerProgress();
        }

        // Create install record
        $install = RegistryExtensionInstall::create([
            'company_uuid'   => session('company'),
            'extension_uuid' => $extension->uuid,
        ]);

        return response()->json(['status' => 'ok', 'install' => $install]);
    }

    /**
     * Uninstall a specific extension for the current company.
     *
     * This function handles the uninstallation of an extension specified by the public ID
     * provided in the request. It first checks if the extension is already uninstalled for
     * the company identified by the `company_uuid` stored in the session. If the extension
     * is not installed, an error response is returned.
     *
     * If the extension is installed, it proceeds to uninstall the extension using Composer
     * and removes the corresponding installation records from the database. If any errors
     * occur during the Composer uninstallation process, an error response is returned.
     *
     * @param InstallExtensionRequest $request the request containing the public ID of the extension to be uninstalled
     *
     * @return \Illuminate\Http\JsonResponse a JSON response indicating the status of the uninstallation process
     */
    public function uninstall(InstallExtensionRequest $request)
    {
        set_time_limit(120 * 6);
        $extension   = RegistryExtension::where('public_id', $request->input('extension'))->first();
        if (!$extension) {
            return $this->extensionNotFound($request->input('extension'), 'uninstalled');
        }
        $uninstalled = false;

        // Check if already uninstalled
        $uninstalled = RegistryExtensionInstall::where(['company_uuid' => session('company'), 'extension_uuid' => $extension->uuid])->doesntExist();
        if ($uninstalled) {
            return response()->error('This extension is not installed.');
        }

        // Check if extensions are pre-installed to system
        $PREINSTALLED_EXTENSIONS = config('registry-bridge.extensions.preinstalled') === true;

        // Run installers if extension needs to be installed
        if ($PREINSTALLED_EXTENSIONS === false) {
            // Uninstall for Composer
            try {
                $extension->currentBundle->uninstallComposerPackage();
            } catch (\Throwable $e) {
                return response()->error($e->getMessage());
            }

            // Unnstall for Console
            try {
                $extension->currentBundle->uninstallEnginePackage();
            } catch (\Throwable $e) {
                return response()->error($e->getMessage());
            }

            // Rebuild Console
            try {
                $extension->currentBundle->buildConsole();
            } catch (\Throwable $e) {
                return response()->error($e->getMessage());
            }
        }

        // Run installer progress if preinstalled
        if ($PREINSTALLED_EXTENSIONS === true) {
            $extension->currentBundle->runUninstallerProgress();
        }

        // Remove install records
        $uninstalled = RegistryExtensionInstall::where([
            'company_uuid'   => session('company'),
            'extension_uuid' => $extension->uuid,
        ])->delete();

        return response()->json(['status' => 'ok', 'uninstalled' => $uninstalled]);
    }

    /**
     * Error response for an extension that has no local record.
     *
     * Records listed from the central registry catalog cannot be installed or
     * uninstalled yet; they get a clear message instead of a null dereference.
     *
     * @param mixed $publicId the requested extension public id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function extensionNotFound($publicId, string $action)
    {
        $publicId = is_string($publicId) ? $publicId : null;
        if (CentralCatalog::enabled() && CentralCatalog::find($publicId)) {
            return response()->error('This extension is listed from the central Fleetbase registry and cannot be ' . $action . ' from this instance yet. Installing from the central registry will be available once the Registry Service launches.', 400);
        }

        return response()->error('Unable to find extension to be ' . $action . '.', 404);
    }
}
