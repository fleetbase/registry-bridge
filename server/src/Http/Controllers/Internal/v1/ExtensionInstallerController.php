<?php

namespace Fleetbase\RegistryBridge\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\RegistryBridge\Http\Requests\InstallExtensionRequest;
use Fleetbase\RegistryBridge\Models\RegistryExtension;
use Fleetbase\RegistryBridge\Models\RegistryExtensionInstall;
use Fleetbase\RegistryBridge\Models\RegistryServiceInstall;
use Fleetbase\RegistryBridge\Support\Installer\ExtensionRef;
use Fleetbase\RegistryBridge\Support\Installer\InstallerException;
use Fleetbase\RegistryBridge\Support\Installer\InstallerService;

class ExtensionInstallerController extends Controller
{
    /**
     * Queue an install of an extension for the current company.
     *
     * Returns `202 { status: "queued", job }` straight away; progress arrives on
     * the `install.{company}.{extension}` socket channel and through
     * GET installer/jobs/{id}. When `registry-bridge.installer.async` is off
     * the job runs inside the request and the response is
     * `{ status: "ok", job, install }` as before.
     *
     * Instances with preinstalled extensions (cloud) only record the install.
     */
    public function install(InstallExtensionRequest $request, InstallerService $installer)
    {
        if (config('registry-bridge.extensions.preinstalled') === true && $this->isLocalExtension($request)) {
            return $this->installPreinstalled($request);
        }

        return $this->enqueue($installer, 'install', $request);
    }

    /**
     * Queue an uninstall of an extension for the current company. Same
     * responses as install(); progress arrives on `uninstall.{company}.{extension}`.
     */
    public function uninstall(InstallExtensionRequest $request, InstallerService $installer)
    {
        if (config('registry-bridge.extensions.preinstalled') === true && $this->isLocalExtension($request)) {
            return $this->uninstallPreinstalled($request);
        }

        return $this->enqueue($installer, 'uninstall', $request);
    }

    /**
     * An installer job of the current company: status, stage, progress, queue
     * position and, once finished, the result (failed stage, message and the
     * last log lines on failure).
     */
    public function job(string $id, InstallerService $installer)
    {
        $job = $installer->find($id, session('company'));
        if ($job === null) {
            return response()->error('Installer job not found.', 404);
        }

        return response()->json(['job' => $job]);
    }

    private function enqueue(InstallerService $installer, string $action, InstallExtensionRequest $request)
    {
        $async = config('registry-bridge.installer.async', true) !== false;

        try {
            $job = $installer->request($action, (string) $request->input('extension'), $request->input('version'), session('company'), session('user'), !$async);
        } catch (InstallerException $e) {
            return response()->error($e->getMessage());
        }

        if ($async) {
            return response()->json(['status' => 'queued', 'job' => $job], 202);
        }

        if (($job['status'] ?? null) === 'failed') {
            return response()->error($job['message'] ?? 'The installer job failed.');
        }

        $install = ($job['extension']['source'] ?? ExtensionRef::LOCAL) === ExtensionRef::REGISTRY_SERVICE
            ? RegistryServiceInstall::where(['company_uuid' => session('company'), 'extension_id' => $job['extension']['id'] ?? null])->first()
            : RegistryExtensionInstall::where(['company_uuid' => session('company'), 'extension_uuid' => $job['extension']['uuid'] ?? null])->first();

        return response()->json(['status' => 'ok', 'job' => $job, $action === 'install' ? 'install' : 'uninstalled' => $action === 'install' ? $install : $install === null]);
    }

    /**
     * Whether the requested extension is in this instance's registry tables.
     * Registry Service extensions go through the InstallerService, which
     * handles preinstalled mode itself and reports to the service.
     */
    private function isLocalExtension(InstallExtensionRequest $request): bool
    {
        return RegistryExtension::where('public_id', $request->input('extension'))->exists();
    }

    private function installPreinstalled(InstallExtensionRequest $request)
    {
        $extension = RegistryExtension::where('public_id', $request->input('extension'))->first();

        $installed = RegistryExtensionInstall::where(['company_uuid' => session('company'), 'extension_uuid' => $extension->uuid])->exists();
        if ($installed) {
            return response()->error('This extension is already installed.');
        }

        $extension->currentBundle->runInstallerProgress();

        $install = RegistryExtensionInstall::create([
            'company_uuid'   => session('company'),
            'extension_uuid' => $extension->uuid,
        ]);

        return response()->json(['status' => 'ok', 'install' => $install]);
    }

    private function uninstallPreinstalled(InstallExtensionRequest $request)
    {
        $extension = RegistryExtension::where('public_id', $request->input('extension'))->first();

        $notInstalled = RegistryExtensionInstall::where(['company_uuid' => session('company'), 'extension_uuid' => $extension->uuid])->doesntExist();
        if ($notInstalled) {
            return response()->error('This extension is not installed.');
        }

        $extension->currentBundle->runUninstallerProgress();

        $uninstalled = RegistryExtensionInstall::where([
            'company_uuid'   => session('company'),
            'extension_uuid' => $extension->uuid,
        ])->delete();

        return response()->json(['status' => 'ok', 'uninstalled' => $uninstalled]);
    }
}
