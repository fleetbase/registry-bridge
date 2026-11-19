<?php

namespace Fleetbase\RegistryBridge\Support\AutoUpdate\Contracts;

/**
 * Installed extensions with an automatic update policy, and what the auto-updater last did for each.
 */
interface AutoUpdateInstalls
{
    /**
     * Installs whose policy is not `none`.
     *
     * @return array<int, array{company: string, extension_id: string, name: string, version: ?string, policy: string, last: ?array<string, mixed>}>
     */
    public function withPolicy(): array;

    /**
     * Keep the outcome of the last automatic update attempt (`version`, `job`, `status`, `message`, `at`).
     *
     * @param array<string, mixed> $state
     */
    public function remember(string $company, string $extensionId, array $state): void;
}
