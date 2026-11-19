<?php

namespace Fleetbase\RegistryBridge\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\RegistryBridge\Exceptions\RegistryMaintenanceException;
use Fleetbase\RegistryBridge\Http\Requests\InstallExtensionRequest;
use Fleetbase\RegistryBridge\Models\RegistryServiceInstall;
use Fleetbase\RegistryBridge\Support\Installer\InstallerException;
use Fleetbase\RegistryBridge\Support\Installer\InstallerService;
use Fleetbase\RegistryBridge\Support\RegistryMaintenance;

class ExtensionInstallerController extends Controller
{
    /**
     * Queue an install of a Registry Service extension for the current company.
     *
     * Returns `202 { status: "queued", job }` straight away; progress arrives on
     * the `install.{company}.{extension}` socket channel and through
     * GET installer/jobs/{id}. When `registry-bridge.installer.async` is off
     * the job runs inside the request and the response is
     * `{ status: "ok", job, install }`.
     *
     * Instances with preinstalled extensions (cloud) only record the install
     * (see InstallerService).
     */
    public function install(InstallExtensionRequest $request, InstallerService $installer)
    {
        return $this->enqueue($installer, 'install', $request);
    }

    /**
     * Queue an uninstall of an extension for the current company. Same
     * responses as install(); progress arrives on `uninstall.{company}.{extension}`.
     */
    public function uninstall(InstallExtensionRequest $request, InstallerService $installer)
    {
        return $this->enqueue($installer, 'uninstall', $request);
    }

    /**
     * Queue an update of an installed extension, to `version` or the newest compatible release. Same
     * responses as install(); progress arrives on `install.{company}.{extension}`.
     */
    public function update(InstallExtensionRequest $request, InstallerService $installer)
    {
        return $this->enqueue($installer, 'update', $request);
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

    /**
     * Refused with 503 while the cutover freeze is on (`registry:maintenance`); the server CLI is not frozen.
     */
    private function enqueue(InstallerService $installer, string $action, InstallExtensionRequest $request)
    {
        try {
            RegistryMaintenance::guard();
        } catch (RegistryMaintenanceException $e) {
            return response()->error($e->getMessage(), 503, ['maintenance' => true]);
        }

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

        $install = RegistryServiceInstall::where(['company_uuid' => session('company'), 'extension_id' => $job['extension']['id'] ?? null])->first();

        return response()->json(['status' => 'ok', 'job' => $job, $action === 'uninstall' ? 'uninstalled' : 'install' => $action === 'uninstall' ? $install === null : $install]);
    }
}
