<?php

namespace Fleetbase\RegistryBridge\Support;

/**
 * Per-process credentials and package specs for installing from the Registry Service.
 *
 * The instance token never touches the filesystem: Composer reads it from `COMPOSER_AUTH`, npm from
 * `NPM_CONFIG_//<host>/npm/:_authToken`, and pnpm (which ignores `//host` keys in the environment) from the
 * secret-free `resources/npm/registry.npmrc`, whose `${…}` placeholders are filled from the same environment.
 * Nothing here writes `auth.json` or `.npmrc` files.
 *
 * Engines are added by tarball URL (`<service>/npm/<name>/-/<basename>-<version>.tgz`) instead of remapping
 * their npm scope, so dependencies in the same scope (for example `@fleetbase/ember-core`) keep resolving from
 * the public npm registry.
 */
class PackageAuth
{
    /** Composer repository key written into the API's composer.json. */
    public const COMPOSER_REPOSITORY_NAME = 'fleetbase-registry-service';

    public const NPM_AUTH_KEY_ENV = 'FLEETBASE_REGISTRY_NPM_AUTH_KEY';

    public const NPM_TOKEN_ENV = 'FLEETBASE_REGISTRY_TOKEN';

    public function __construct(protected RegistryConfig $config)
    {
    }

    /**
     * Environment for a Composer process.
     *
     * Merges the instance token into an existing `COMPOSER_AUTH` (other hosts' credentials are kept).
     *
     * @param string|null $existingComposerAuth the `COMPOSER_AUTH` already in the environment, if any
     *
     * @return array<string, string>
     */
    public function composerEnv(?string $existingComposerAuth = null): array
    {
        $serviceUrl = $this->config->serviceUrl();
        $token      = $this->config->instanceToken();
        if (!$serviceUrl || !$token) {
            return [];
        }

        $auth = [];
        if (is_string($existingComposerAuth) && trim($existingComposerAuth) !== '') {
            $decoded = json_decode($existingComposerAuth, true);
            $auth    = is_array($decoded) ? $decoded : [];
        }

        $bearer                                       = is_array($auth['bearer'] ?? null) ? $auth['bearer'] : [];
        $bearer[static::composerHostKey($serviceUrl)] = $token;
        $auth['bearer']                               = $bearer;

        return ['COMPOSER_AUTH' => (string) json_encode($auth, JSON_UNESCAPED_SLASHES)];
    }

    /**
     * Environment for a pnpm or npm process.
     *
     * @return array<string, string>
     */
    public function npmEnv(): array
    {
        $npmUrl = $this->config->npmRegistryUrl();
        $token  = $this->config->instanceToken();
        if (!$npmUrl || !$token) {
            return [];
        }

        $authKey = static::npmAuthKey($npmUrl);

        /** @var array<string, string> $env */
        $env = [
            // npm CLI reads registry credentials straight from the environment
            'NPM_CONFIG_' . $authKey => $token,
            // pnpm reads them from a user config, so point it at the placeholder-only file
            'npm_config_userconfig'  => static::npmrcPath(),
            static::NPM_AUTH_KEY_ENV => $authKey,
            static::NPM_TOKEN_ENV    => $token,
        ];

        return $env;
    }

    /**
     * Composer repository entry for the service (`composer config repositories.<name> <json>`).
     *
     * @return array{type: string, url: string, canonical: bool}|null
     */
    public function composerRepository(): ?array
    {
        $url = $this->config->composerRepositoryUrl();

        return $url ? ['type' => 'composer', 'url' => $url, 'canonical' => false] : null;
    }

    /**
     * Tarball URL the engine is added from, per the service's npm routes.
     */
    public function npmTarballUrl(string $packageName, string $version): ?string
    {
        $npmUrl = $this->config->npmRegistryUrl();
        if (!$npmUrl || $packageName === '' || $version === '') {
            return null;
        }

        $basename = str_contains($packageName, '/') ? substr($packageName, strrpos($packageName, '/') + 1) : $packageName;

        return $npmUrl . $packageName . '/-/' . $basename . '-' . $version . '.tgz';
    }

    /**
     * `pnpm add` argument for an engine: `<name>@<tarball url>`, or `<name>@<version>` without a service.
     */
    public function npmInstallSpec(string $packageName, string $version): string
    {
        $tarball = $this->npmTarballUrl($packageName, $version);

        return $packageName . '@' . ($tarball ?? $version);
    }

    /**
     * `composer require` argument for an API package: `<name>:<version>`.
     */
    public static function composerInstallSpec(string $packageName, string $version): string
    {
        return $version !== '' ? $packageName . ':' . $version : $packageName;
    }

    /**
     * Key Composer matches bearer credentials on: the host, plus the port when one is given.
     */
    public static function composerHostKey(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        return $port ? $host . ':' . $port : $host;
    }

    /**
     * npm credential key for a registry URL: `//host[:port]/path/:_authToken`.
     */
    public static function npmAuthKey(string $registryUrl): string
    {
        $withoutScheme = (string) preg_replace('#^https?:#i', '', $registryUrl);

        return rtrim($withoutScheme, '/') . '/:_authToken';
    }

    /**
     * The placeholder-only npm user config shipped with this package.
     */
    public static function npmrcPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/npm/registry.npmrc';
    }
}
