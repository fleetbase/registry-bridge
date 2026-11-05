<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

use Fleetbase\RegistryBridge\Exceptions\RegistryException;
use Fleetbase\RegistryBridge\Exceptions\RegistryServiceException;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\InstallReporter;
use Fleetbase\RegistryBridge\Support\RegistryClient;
use Fleetbase\RegistryBridge\Support\RegistryConfig;

/**
 * Reports installs of Registry Service releases to the instance API
 * (`POST /v1/instances/me/installs`, `DELETE /v1/instances/me/installs/{extension}`).
 *
 * `installing` doubles as the licence check: the service refuses a paid
 * extension without an active licence and seat (403) or a listing whose hosting
 * excludes this instance (422), and the installer stops before anything changes.
 * `installed`, `failed` and uninstall reports are best effort. Releases from
 * this instance's own tables are not reported.
 */
class ServiceInstallReporter implements InstallReporter
{
    /** Longest error text sent to the service. */
    public const ERROR_LIMIT = 2000;

    public function __construct(
        private readonly RegistryClient $client,
        private readonly RegistryConfig $config,
    ) {
    }

    public function starting(string $action, ExtensionRef $extension, Release $release, string $company, ?string $user = null): void
    {
        if (!$extension->isFromRegistryService()) {
            return;
        }

        if (!$this->config->isRegistered()) {
            throw new InstallerException('Register this instance with the registry service before installing extensions from it.');
        }

        try {
            $this->client->reportInstall($this->payload($extension, $release, 'installing', $user));
        } catch (RegistryServiceException $e) {
            throw new InstallerException($e->getMessage(), $e->status(), $e);
        } catch (RegistryException $e) {
            throw new InstallerException('The registry service could not be reached to start the install: ' . $e->getMessage(), 0, $e);
        }
    }

    public function finished(string $action, ExtensionRef $extension, Release $release, string $company, ?string $user = null, bool $lastOnInstance = true): void
    {
        if (!$extension->isFromRegistryService() || !$this->config->isRegistered()) {
            return;
        }

        if ($action !== 'uninstall') {
            $this->client->reportInstall($this->payload($extension, $release, 'installed', $user));

            return;
        }

        // The seat belongs to the instance: keep it while another company still has the extension
        if (!$lastOnInstance) {
            return;
        }

        try {
            $this->client->reportUninstall($extension->id);
        } catch (RegistryServiceException $e) {
            // 404: the service never saw the install (an earlier report was lost); nothing to release
            if ($e->status() !== 404) {
                throw $e;
            }
        }
    }

    public function failed(string $action, ExtensionRef $extension, Release $release, string $company, string $message, ?string $user = null): void
    {
        if (!$extension->isFromRegistryService() || !$this->config->isRegistered()) {
            return;
        }

        $this->client->reportInstall([
            ...$this->payload($extension, $release, 'failed', $user),
            'error' => strlen($message) > self::ERROR_LIMIT ? substr($message, 0, self::ERROR_LIMIT) . '…' : $message,
        ]);
    }

    /**
     * @return array<string, string|null>
     */
    private function payload(ExtensionRef $extension, Release $release, string $status, ?string $user): array
    {
        return [
            'extension'    => $extension->id,
            'version'      => $release->version,
            'status'       => $status,
            'installed_by' => $user,
        ];
    }
}
