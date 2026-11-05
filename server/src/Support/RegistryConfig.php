<?php

namespace Fleetbase\RegistryBridge\Support;

use Fleetbase\Support\Utils as SupportUtils;

/**
 * Resolved Registry Service settings for this instance.
 *
 * The Registry Service (`registry.service_url`) serves the catalog, the npm and Composer package endpoints
 * and the instance API. Built from the merged `registry-bridge.registry` config (settings override env), or
 * from a plain array in tests. The stored settings are read again once per Octane request or queued job
 * (`RegistrySettings`), so a registration made in another process or worker applies without restarting workers.
 *
 * @phpstan-consistent-constructor
 */
class RegistryConfig
{
    public const V2 = 'v2';

    public const DEFAULT_SERVICE_URL = 'https://registry.fleetbase.io';

    public const KIND_SELF_HOSTED = 'self_hosted';

    public const KIND_CLOUD = 'cloud';

    /**
     * @param array<string, mixed> $registry the `registry-bridge.registry` config array
     */
    public function __construct(protected array $registry = [])
    {
    }

    /**
     * Settings for the running application, with the stored settings applied for this request or job.
     */
    public static function current(): static
    {
        RegistrySettings::instance()->ensureApplied();

        $registry = config('registry-bridge.registry', []);

        return new static(is_array($registry) ? $registry : []);
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
     * Whether the catalog, installs and updates go through the Registry Service.
     */
    public function usesService(): bool
    {
        return $this->serviceUrl() !== null;
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
     * The registry organization this instance is registered to (`id`, `slug`, `name`): it owns the licences
     * bought here, not the console company. Null before registration.
     *
     * @return array{id: string|null, slug: string|null, name: string|null}|null
     */
    public function organization(): ?array
    {
        $organization = $this->instance()['organization'] ?? null;
        if (!is_array($organization)) {
            return null;
        }

        $value = fn (string $key) => is_string($organization[$key] ?? null) && $organization[$key] !== '' ? $organization[$key] : null;
        $out   = ['id' => $value('id'), 'slug' => $value('slug'), 'name' => $value('name')];

        return array_filter($out) !== [] ? $out : null;
    }

    /**
     * Instance kind reported at registration and used to match an extension's hosting.
     */
    public function instanceKind(): string
    {
        $kind = $this->string('instance_kind');

        return in_array($kind, [static::KIND_SELF_HOSTED, static::KIND_CLOUD], true) ? $kind : static::KIND_SELF_HOSTED;
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
     * Publisher portal URL (the service's website), or null without a service.
     */
    public function publisherUrl(): ?string
    {
        $url = $this->string('publisher_url');
        if ($url !== '') {
            return static::normalizeUrl($url);
        }

        return $this->usesService() ? $this->serviceUrl() : null;
    }

    /**
     * Whether purchases go through the service checkout (`POST /v1/instances/me/purchases/checkout`).
     *
     * `checkout` is `auto` (on for a registered instance unless the service answered that it has no
     * checkout), or a boolean to force it.
     *
     * @param bool $serviceSupportsCheckout false once the service answered 404 or 405 to a checkout
     */
    public function checkoutEnabled(bool $serviceSupportsCheckout = true): bool
    {
        if (!$this->usesService() || !$this->isRegistered()) {
            return false;
        }

        $mode = $this->registry['checkout'] ?? 'auto';
        if (is_string($mode) && in_array(strtolower(trim($mode)), ['', 'auto'], true)) {
            return $serviceSupportsCheckout;
        }

        return SupportUtils::castBoolean($mode) === true;
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
