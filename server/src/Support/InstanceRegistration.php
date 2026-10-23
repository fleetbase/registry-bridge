<?php

namespace Fleetbase\RegistryBridge\Support;

use Composer\InstalledVersions;
use Fleetbase\Models\Setting;
use Fleetbase\RegistryBridge\Exceptions\RegistryServiceException;
use Fleetbase\RegistryBridge\Exceptions\RegistryUnavailableException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Support\Arr;

/**
 * Registering this instance with the Registry Service and keeping its credentials.
 *
 * The instance token is stored in the core-api `settings` table under `registry-bridge.registry.instance_token`,
 * encrypted with the application key; the service URL and what the service returned about the instance are
 * stored beside it (`registry-bridge.registry.service_url`, `registry-bridge.registry.instance`). The service
 * provider decrypts the token into `config('registry-bridge.registry.instance_token')` at boot.
 */
class InstanceRegistration
{
    public const SETTING_SERVICE_URL = 'registry-bridge.registry.service_url';

    public const SETTING_API_VERSION = 'registry-bridge.registry.api_version';

    public const SETTING_INSTANCE_TOKEN = 'registry-bridge.registry.instance_token';

    public const SETTING_INSTANCE = 'registry-bridge.registry.instance';

    /**
     * Exchange a registration code for an instance token and store it (the admin endpoint and `registry:register`).
     *
     * The service URL defaults to the configured one; name, host and console version default to the app name,
     * the app URL and the console's package.json.
     *
     * @return array<string, mixed> the stored instance summary (never the token)
     *
     * @throws RegistryServiceException     with status 422 when the code or service URL is missing, or the service's status when it refuses the code
     * @throws RegistryUnavailableException when the service cannot be reached
     */
    public static function register(string $code, ?string $serviceUrl = null, ?string $name = null, ?string $host = null, ?string $consoleVersion = null): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new RegistryServiceException('Enter the registration code from your registry organization.', 422);
        }

        $config     = RegistryConfig::current();
        $serviceUrl = $serviceUrl !== null && trim($serviceUrl) !== '' ? RegistryConfig::normalizeUrl(trim($serviceUrl)) : $config->serviceUrl();
        if (!$serviceUrl) {
            throw new RegistryServiceException('Set the Registry Service URL before registering this instance.', 422);
        }

        $payload = static::payload(
            $code,
            $name ?: (static::configString('app.name') ?? 'Fleetbase'),
            $host ?: static::configString('app.url'),
            $config->instanceKind(),
            $consoleVersion ?: static::detectConsoleVersion(static::configString('fleetbase.console.path')),
            static::detectApiVersion()
        );

        $registration = (new RegistryClient($serviceUrl, null, RegistryConfig::V2, $config))->registerInstance($payload);

        return static::store($registration, $serviceUrl);
    }

    /**
     * Body for `POST /v1/instances/register`.
     *
     * @return array<string, string>
     */
    public static function payload(string $code, string $name, ?string $host, string $kind, ?string $consoleVersion, ?string $apiVersion): array
    {
        return array_filter([
            'code'            => strtoupper(trim($code)),
            'name'            => trim($name) !== '' ? trim($name) : 'Fleetbase',
            'kind'            => $kind,
            'host'            => $host ? trim($host) : null,
            'console_version' => static::cleanVersion($consoleVersion),
            'api_version'     => static::cleanVersion($apiVersion),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * What is kept about the instance from the register response (never the token).
     *
     * @param array<string, mixed> $instance `instance` from the register response
     *
     * @return array<string, mixed>
     */
    public static function instanceSummary(array $instance, string $serviceUrl, ?string $token, string $registeredAt): array
    {
        return [
            'id'                => Arr::get($instance, 'id'),
            'name'              => Arr::get($instance, 'name'),
            'host'              => Arr::get($instance, 'host'),
            'kind'              => Arr::get($instance, 'kind'),
            'status'            => Arr::get($instance, 'status'),
            'organization'      => [
                'id'   => Arr::get($instance, 'organization.id'),
                'slug' => Arr::get($instance, 'organization.slug'),
                'name' => Arr::get($instance, 'organization.name'),
            ],
            'service_url'       => $serviceUrl,
            'token_hint'        => static::maskToken($token),
            'registered_at'     => $registeredAt,
        ];
    }

    /**
     * Masked token for display: `flbi_…a1b2`.
     */
    public static function maskToken(?string $token): ?string
    {
        if (!is_string($token) || $token === '') {
            return null;
        }

        $prefix = str_contains($token, '_') ? substr($token, 0, strpos($token, '_') + 1) : '';

        return $prefix . '…' . substr($token, -4);
    }

    /**
     * Encrypt the token for the settings table.
     */
    public static function encryptToken(string $token, StringEncrypter $encrypter): string
    {
        return $encrypter->encryptString($token);
    }

    /**
     * Decrypt a stored token; null when it is missing or cannot be decrypted (for example after an app key change).
     */
    public static function decryptToken(mixed $stored, StringEncrypter $encrypter): ?string
    {
        if (!is_string($stored) || $stored === '') {
            return null;
        }

        try {
            $token = $encrypter->decryptString($stored);
        } catch (\Throwable $e) {
            return null;
        }

        return $token !== '' ? $token : null;
    }

    /**
     * Persist a successful registration and apply it to the running config.
     *
     * @param array{token: string, instance: array<string, mixed>} $registration
     *
     * @return array<string, mixed> the stored instance summary
     */
    public static function store(array $registration, string $serviceUrl): array
    {
        $summary = static::instanceSummary($registration['instance'], $serviceUrl, $registration['token'], date(DATE_ATOM));

        static::configure(static::SETTING_SERVICE_URL, $serviceUrl);
        static::configure(static::SETTING_INSTANCE_TOKEN, static::encryptToken($registration['token'], app('encrypter')));
        static::configure(static::SETTING_INSTANCE, $summary);

        config([
            'registry-bridge.registry.service_url'    => $serviceUrl,
            'registry-bridge.registry.instance_token' => $registration['token'],
            'registry-bridge.registry.instance'       => $summary,
        ]);

        RegistryClient::flush();

        return $summary;
    }

    /**
     * Write one setting (overridden in tests, which have no database).
     */
    protected static function configure(string $key, mixed $value): void
    {
        Setting::configure($key, $value);
    }

    protected static function configString(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Forget the instance token and registration details (the service URL is kept).
     */
    public static function forget(): void
    {
        Setting::where('key', static::SETTING_INSTANCE_TOKEN)->delete();
        Setting::where('key', static::SETTING_INSTANCE)->delete();

        config([
            'registry-bridge.registry.instance_token' => null,
            'registry-bridge.registry.instance'       => [],
        ]);

        RegistryClient::flush();
    }

    /**
     * Version of fleetbase/core-api, which is what extension `compat.api` ranges are written against.
     */
    public static function detectApiVersion(): ?string
    {
        try {
            return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('fleetbase/core-api')
                ? static::cleanVersion(InstalledVersions::getPrettyVersion('fleetbase/core-api'))
                : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Console version from the console's package.json when the console checkout is reachable from the API.
     */
    public static function detectConsoleVersion(?string $consolePath): ?string
    {
        if (!$consolePath) {
            return null;
        }

        $file = rtrim($consolePath, '/') . '/package.json';
        if (!is_readable($file)) {
            return null;
        }

        $package = json_decode((string) file_get_contents($file), true);

        return is_array($package) ? static::cleanVersion($package['version'] ?? null) : null;
    }

    /**
     * Strip a leading `v` and reject anything that is not a plain version string.
     */
    public static function cleanVersion(mixed $version): ?string
    {
        if (!is_string($version)) {
            return null;
        }

        $version = ltrim(trim($version), 'vV');

        return preg_match('/^\d+(\.\d+){0,2}([-+][0-9A-Za-z.-]+)?$/', $version) ? $version : null;
    }
}
