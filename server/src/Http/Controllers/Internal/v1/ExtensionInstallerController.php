<?php

namespace Fleetbase\RegistryBridge\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\RegistryBridge\Exceptions\InstallFailedException;
use Fleetbase\RegistryBridge\Exceptions\RegistryException;
use Fleetbase\RegistryBridge\Http\Requests\InstallExtensionRequest;
use Fleetbase\RegistryBridge\Models\RegistryExtension;
use Fleetbase\RegistryBridge\Models\RegistryExtensionInstall;
use Fleetbase\RegistryBridge\Models\RegistryServiceInstall;
use Fleetbase\RegistryBridge\Support\CentralCatalog;
use Fleetbase\RegistryBridge\Support\ProcessRunner;
use Fleetbase\RegistryBridge\Support\RegistryClient;
use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Fleetbase\RegistryBridge\Support\ServiceInstaller;
use Illuminate\Support\Facades\Auth;

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
     * Extensions that have no local record are installed from the Registry Service when one is
     * configured (see `installFromService()`).
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
            $config = RegistryConfig::current();
            if ($config->usesService()) {
                return $this->installFromService((string) $request->input('extension'), $config);
            }

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
     * Extensions installed from the Registry Service are uninstalled by `uninstallFromService()`.
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
            $serviceInstall = RegistryServiceInstall::where(['company_uuid' => session('company'), 'extension_id' => $request->input('extension')])->first();
            if ($serviceInstall) {
                return $this->uninstallFromService($serviceInstall, RegistryConfig::current());
            }

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
     * Install an extension served by the Registry Service.
     *
     * The installer reports `installing` first, so a licence, hosting or seat refusal from the service
     * stops the install before any package is touched; Composer and pnpm then run with the instance
     * token injected per process, and the outcome is reported back. A local record keeps the engine
     * loading and the Installed page working when the service is unreachable.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function installFromService(string $id, RegistryConfig $config)
    {
        $client = RegistryClient::fromConfig($config);

        try {
            $listing = $client->catalogExtension($id);
        } catch (RegistryException $e) {
            return response()->error($e->getMessage(), 503);
        }

        if (!$listing) {
            return response()->error('Unable to find extension to be installed.', 404);
        }

        $extensionId = (string) ($listing['id'] ?? $id);
        $installed   = RegistryServiceInstall::where(['company_uuid' => session('company'), 'extension_id' => $extensionId])->exists();
        if ($installed) {
            return response()->error('This extension is already installed.');
        }

        $runner    = new ProcessRunner(implode('.', ['install', session('company'), $extensionId]));
        $installer = new ServiceInstaller($client, $config, $runner, fn (string $process, string $step, int $progress) => $runner->publish($process, $step, $progress));

        try {
            $result = $installer->install($listing, $this->installContext());
        } catch (InstallFailedException $e) {
            return response()->error($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 500 ? $e->getCode() : 400);
        }

        $install = RegistryServiceInstall::create([
            'company_uuid'      => session('company'),
            'installed_by_uuid' => session('user'),
            'extension_id'      => $extensionId,
            'slug'              => $listing['slug'] ?? null,
            'name'              => $listing['name'] ?? null,
            'npm_package'       => $result['npm'],
            'composer_package'  => $result['composer'],
            'version'           => $result['version'],
            'release_id'        => $result['release'],
            'service_url'       => $config->serviceUrl(),
            'meta'              => ['activation' => $result['activation'], 'reported' => $result['reported']],
        ]);

        return response()->json(['status' => 'ok', 'install' => $install, 'activation' => $result['activation']]);
    }

    /**
     * Uninstall an extension that was installed from the Registry Service.
     *
     * Packages are only removed, and the licence seat only released, when no other company on this
     * instance still has the extension installed.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function uninstallFromService(RegistryServiceInstall $install, RegistryConfig $config)
    {
        $client  = RegistryClient::fromConfig($config);
        $listing = $install->toListing();

        $sharedWithOthers = RegistryServiceInstall::where('extension_id', $install->extension_id)
            ->where('company_uuid', '!=', $install->company_uuid)
            ->exists();

        $runner    = new ProcessRunner(implode('.', ['uninstall', session('company'), $install->extension_id]));
        $installer = new ServiceInstaller($client, $config, $runner, fn (string $process, string $step, int $progress) => $runner->publish($process, $step, $progress));
        $context   = $this->installContext();
        if ($sharedWithOthers) {
            $context['preinstalled'] = true;
            $context['report']       = false;
        }

        try {
            $result = $installer->uninstall($listing, $context);
        } catch (InstallFailedException $e) {
            return response()->error($e->getMessage());
        }

        $install->delete();

        return response()->json(['status' => 'ok', 'uninstalled' => 1, 'deactivated' => $result['deactivated'], 'reported' => $result['reported']]);
    }

    /**
     * Paths, environment and actor the service installer runs with.
     *
     * @return array<string, mixed>
     */
    protected function installContext(): array
    {
        $composerAuth = getenv('COMPOSER_AUTH');

        return [
            'api_path'      => base_path(),
            'console_path'  => config('fleetbase.console.path'),
            'environment'   => config('app.env'),
            'preinstalled'  => config('registry-bridge.extensions.preinstalled') === true,
            'installed_by'  => Auth::user()?->email,
            'composer_auth' => is_string($composerAuth) ? $composerAuth : null,
        ];
    }

    /**
     * Error response for an extension that has no local record.
     *
     * Without a Registry Service, records listed from the cloud catalog (v1) cannot be
     * installed or uninstalled from this instance; they get a clear message instead of
     * a null dereference.
     *
     * @param mixed $publicId the requested extension public id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function extensionNotFound($publicId, string $action)
    {
        $publicId = is_string($publicId) ? $publicId : null;
        if (!RegistryConfig::current()->usesService() && CentralCatalog::enabled() && CentralCatalog::find($publicId)) {
            return response()->error('This extension is listed from the central Fleetbase registry and cannot be ' . $action . ' from this instance. Configure a Registry Service URL and register this instance to install from the central registry.', 400);
        }

        return response()->error('Unable to find extension to be ' . $action . '.', 404);
    }
}
