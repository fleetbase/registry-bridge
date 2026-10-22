<?php

namespace Fleetbase\RegistryBridge\Support;

/**
 * Resolved registry settings for this instance.
 *
 * Two registries can back the module:
 *
 * - `v2`: the Registry Service (`registry.service_url`), which serves the catalog, the npm and Composer
 *   package endpoints and the instance API. Used whenever a service URL is configured, unless
 *   `registry.api_version` is pinned to `v1`.
 * - `v1`: the cloud registry-bridge endpoints (`registry.host` + `~registry/v1/extensions`), kept as the
 *   fallback when no service URL is configured.
 *
 * Built from the merged `registry-bridge.registry` config (settings override env), or from a plain array in tests.
 *
 * @phpstan-consistent-constructor
 */
class RegistryConfig
{
    public const V1 = 'v1';

    public const V2 = 'v2';

    public const DEFAULT_HOST = 'https://registry.fleetbase.io';

    public const KIND_SELF_HOSTED = 'self_hosted';

    public const KIND_CLOUD = 'cloud';

    /**
     * @param array<string, mixed> $registry the `registry-bridge.registry` config array
     */
    public function __construct(protected array $registry = [])
    {
    }

    /**
     * Settings for the running application.
     */
    public static function current(): static
    {
        $registry = config('registry-bridge.registry', []);

        return new static(is_array($registry) ? $registry : []);
    }

    /**
     * Legacy (v1) registry host.
     */
    public function host(): string
    {
        $host = $this->string('host');

        return $host !== '' ? $host : static::DEFAULT_HOST;
    }

    /**
     * Legacy (v1) bearer token.
     */
    public function token(): ?string
    {
        return $this->string('token') ?: null;
    }

    /**
     * Registry Service base URL without a trailing slash, or null when none is configured.
     */
    public function serviceUrl(): ?string
    {
        $url = $this->string('service_url');

        return $url !== '' ? static::normalizeUrl($url) : null;
    }

    /**
     * The API version in use: the configured one when valid, else `v2` with a service URL and `v1` without.
     */
    public function apiVersion(): string
    {
        if (strtolower($this->string('api_version')) === static::V1) {
            return static::V1;
        }

        // v2 needs a service to talk to; without one the cloud endpoints stay the fallback
        return $this->serviceUrl() ? static::V2 : static::V1;
    }

    /**
     * Whether the catalog, installs and updates go through the Registry Service.
     */
    public function usesService(): bool
    {
        return $this->apiVersion() === static::V2;
    }

    /**
     * Instance token (`flbi_…`) issued at registration, already decrypted.
     */
    public function instanceToken(): ?string
    {
        return $this->string('instance_token') ?: null;
    }

    /**
     * Whether this instance holds an instance token for the service.
     */
    public function isRegistered(): bool
    {
        return $this->instanceToken() !== null;
    }

    /**
     * What the service returned about this instance at registration (`id`, `name`, `organization`, …).
     *
     * @return array<string, mixed>
     */
    public function instance(): array
    {
        $instance = $this->registry['instance'] ?? [];

        return is_array($instance) ? $instance : [];
    }

    /**
     * Instance kind reported at registration and used to match an extension's hosting.
     */
    public function instanceKind(): string
    {
        $kind = $this->string('instance_kind');
        if (in_array($kind, [static::KIND_SELF_HOSTED, static::KIND_CLOUD], true)) {
            return $kind;
        }

        // An instance that runs its own registry tables is Fleetbase Cloud
        return Utils::castBoolean($this->registry['self_hosted'] ?? false) === true ? static::KIND_CLOUD : static::KIND_SELF_HOSTED;
    }

    /**
     * Whether this instance runs its own registry tables (the cloud).
     */
    public function isSelfHostedRegistry(): bool
    {
        return Utils::castBoolean($this->registry['self_hosted'] ?? false) === true;
    }

    /**
     * Base URL for client requests in the active mode.
     */
    public function baseUrl(): string
    {
        return $this->usesService() ? (string) $this->serviceUrl() : static::normalizeUrl($this->host());
    }

    /**
     * npm registry root served by the service (`<service>/npm/`).
     */
    public function npmRegistryUrl(): ?string
    {
        $url = $this->serviceUrl();

        return $url ? $url . '/npm/' : null;
    }

    /**
     * Composer repository root served by the service (`<service>/composer`).
     */
    public function composerRepositoryUrl(): ?string
    {
        $url = $this->serviceUrl();

        return $url ? $url . '/composer' : null;
    }

    /**
     * Prefix a URL with https:// when it has no scheme and drop the trailing slash.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }

        return rtrim($url, '/');
    }

    protected function string(string $key): string
    {
        $value = $this->registry[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }
}
