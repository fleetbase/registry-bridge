<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

use Fleetbase\RegistryBridge\Support\Installer\Contracts\InstallRecorder;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\ReleaseSource;

/**
 * The one entry point for install, uninstall and update requests, shared by
 * the console endpoints and the artisan commands.
 */
class InstallerService
{
    public const ACTIONS = ['install', 'uninstall', 'update'];

    public function __construct(
        private readonly InstallerQueue $queue,
        private readonly InstallerJobStore $store,
        private readonly ReleaseSource $releases,
        private readonly InstallRecorder $recorder,
        private readonly ExtensionInstaller $installer,
        private readonly bool $preinstalled = false,
    ) {
    }

    /**
     * Validate a request and queue it (or run it now with $sync).
     *
     * @return array<string, mixed> the job record
     *
     * @throws InstallerException when the request is refused
     */
    public function request(string $action, string $extension, ?string $version = null, ?string $company = null, ?string $user = null, bool $sync = false): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new InstallerException(sprintf('Unknown installer action "%s".', $action));
        }

        $ref = $this->releases->find($extension);
        if ($ref === null) {
            throw new InstallerException(sprintf('Extension "%s" was not found in the registry.', $extension));
        }

        $companyUuid = $this->recorder->resolveCompany($company);
        if ($companyUuid === null) {
            throw new InstallerException($company === null ? 'This instance has several companies; pass the company to install for (--company).' : sprintf('Company "%s" was not found.', $company));
        }

        $installed = $this->recorder->isInstalled($companyUuid, $ref);
        if ($action === 'install' && $installed) {
            throw new InstallerException('This extension is already installed.');
        }
        if ($action !== 'install' && !$installed) {
            throw new InstallerException('This extension is not installed.');
        }
        if ($action === 'uninstall') {
            $version = null;
        }
        PackageName::assertConstraint($version);

        $active = $this->store->activeFor($companyUuid, $ref->uuid);
        if ($active !== null) {
            return [...$active, 'duplicate' => true];
        }

        $attributes = ['action' => $action, 'extension' => $ref->toArray(), 'version' => $version, 'company' => $companyUuid, 'user' => $user];

        if ($this->preinstalled) {
            return $this->recordOnly($attributes, $ref, $version);
        }

        return $sync ? $this->queue->runNow($attributes, $this->installer) : $this->queue->enqueue($attributes);
    }

    /**
     * A job record, scoped to a company when one is given.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id, ?string $company = null): ?array
    {
        $record = $this->store->find($id);
        if ($record === null || ($company !== null && ($record['company'] ?? null) !== $company)) {
            return null;
        }

        return $record;
    }

    /**
     * Cloud images ship every extension preinstalled: only the install record
     * changes.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    private function recordOnly(array $attributes, ExtensionRef $ref, ?string $version): array
    {
        $record = $this->store->create($attributes);
        if ($attributes['action'] === 'uninstall') {
            $this->recorder->remove($attributes['company'], $ref);
        } else {
            $release = $this->releases->resolve($ref, $version);
            $this->recorder->record($attributes['company'], $ref, $release, ['version' => $release->version, 'mode' => 'preinstalled', 'job' => $record['id']]);
        }

        return $this->store->update($record['id'], ['status' => 'completed', 'progress' => 100, 'result' => ['status' => 'completed', 'mode' => 'preinstalled'], 'finished_at' => date(DATE_ATOM)]) ?? $record;
    }
}
