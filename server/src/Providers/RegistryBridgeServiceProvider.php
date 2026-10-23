<?php

namespace Fleetbase\RegistryBridge\Providers;

use Fleetbase\Models\Setting;
use Fleetbase\Providers\CoreServiceProvider;
use Fleetbase\RegistryBridge\Models\RegistryServiceInstall;
use Fleetbase\RegistryBridge\Support\Installer\ArtifactDownloader;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\EngineCompatChecker;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\InstallRecorder;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\InstallReporter;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\PackageCredentials;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\ProcessRunner;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\ProgressPublisher;
use Fleetbase\RegistryBridge\Support\Installer\Contracts\ReleaseSource;
use Fleetbase\RegistryBridge\Support\Installer\InstallerConfig;
use Fleetbase\RegistryBridge\Support\Installer\InstallerJobStore;
use Fleetbase\RegistryBridge\Support\Installer\InstallerQueue;
use Fleetbase\RegistryBridge\Support\Installer\InstallerService;
use Fleetbase\RegistryBridge\Support\Installer\NodeEngineCompat;
use Fleetbase\RegistryBridge\Support\Installer\PhpEngineCompat;
use Fleetbase\RegistryBridge\Support\Installer\RuntimeEngineManifest;
use Fleetbase\RegistryBridge\Support\Installer\ServiceInstallRecorder;
use Fleetbase\RegistryBridge\Support\Installer\ServiceInstallReporter;
use Fleetbase\RegistryBridge\Support\Installer\ServicePackageCredentials;
use Fleetbase\RegistryBridge\Support\Installer\ServiceReleaseSource;
use Fleetbase\RegistryBridge\Support\Installer\SocketClusterPublisher;
use Fleetbase\RegistryBridge\Support\Installer\SymfonyProcessRunner;
use Fleetbase\RegistryBridge\Support\InstanceRegistration;
use Fleetbase\RegistryBridge\Support\PackageAuth;
use Fleetbase\RegistryBridge\Support\RegistryClient;
use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;

if (!class_exists(CoreServiceProvider::class)) {
    throw new \Exception('Registry Bridge cannot be loaded without `fleetbase/core-api` installed!');
}

/**
 * Registry Bridge service provider.
 */
class RegistryBridgeServiceProvider extends CoreServiceProvider
{
    /**
     * The observers registered with the service provider.
     *
     * @var array
     */
    public $observers = [];

    /**
     * The console commands registered with the service provider.
     *
     * @var array
     */
    public $commands = [
        \Fleetbase\RegistryBridge\Console\Commands\Initialize::class,
        \Fleetbase\RegistryBridge\Console\Commands\InstallExtension::class,
        \Fleetbase\RegistryBridge\Console\Commands\UninstallExtension::class,
        \Fleetbase\RegistryBridge\Console\Commands\UpdateExtension::class,
        \Fleetbase\RegistryBridge\Console\Commands\InstallerJobStatus::class,
        \Fleetbase\RegistryBridge\Console\Commands\RegisterInstance::class,
    ];

    /**
     * The middleware groups registered with the service provider.
     *
     * @var array
     */
    public $middleware = [
        'fleetbase.registry' => [
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
    ];

    /**
     * Register any application services.
     *
     * Within the register method, you should only bind things into the
     * service container. You should never attempt to register any event
     * listeners, routes, or any other piece of functionality within the
     * register method.
     *
     * More information on this can be found in the Laravel documentation:
     * https://laravel.com/docs/8.x/providers
     *
     * @return void
     */
    public function register()
    {
        $this->app->register(CoreServiceProvider::class);
        $this->registerInstaller();
    }

    /**
     * Bind the job-based installer (see Support/Installer). Bindings are lazy,
     * so config merged in boot() is in place when they resolve.
     */
    protected function registerInstaller(): void
    {
        $this->app->singleton(InstallerConfig::class, function () {
            $config      = (array) config('registry-bridge.installer', []);
            $consolePath = $config['console_path'] ?? config('fleetbase.console.path', '/fleetbase/console');

            return InstallerConfig::fromArray([
                ...$config,
                'api_path'          => $config['api_path'] ?? base_path(),
                'console_path'      => $consolePath,
                'work_path'         => $config['work_path'] ?? storage_path('app/registry-installer'),
                'build_environment' => $config['build_environment'] ?? config('app.env', 'production'),
            ]);
        });

        $this->app->bind(ProcessRunner::class, SymfonyProcessRunner::class);
        $this->app->bind(ProgressPublisher::class, SocketClusterPublisher::class);

        // Extensions come from the Registry Service
        $this->app->bind(ReleaseSource::class, function () {
            $config = RegistryConfig::current();

            return new ServiceReleaseSource(
                RegistryClient::fromConfig($config),
                $config,
                fn (string $extension) => RegistryServiceInstall::where('extension_id', $extension)->first()?->toListing(),
            );
        });

        $this->app->bind(InstallRecorder::class, fn () => new ServiceInstallRecorder(RegistryConfig::current()->serviceUrl()));

        $this->app->bind(InstallReporter::class, function () {
            $config = RegistryConfig::current();

            return new ServiceInstallReporter(RegistryClient::fromConfig($config), $config);
        });
        $this->app->bind(PackageCredentials::class, function (Application $app) {
            $config       = $app->make(InstallerConfig::class);
            $composerAuth = getenv('COMPOSER_AUTH');

            return new ServicePackageCredentials(new PackageAuth(RegistryConfig::current()), $config->composerBinary, $config->pnpmBinary, is_string($composerAuth) ? $composerAuth : null);
        });

        $this->app->bind(EngineCompatChecker::class, function (Application $app) {
            $config = $app->make(InstallerConfig::class);
            $driver = config('registry-bridge.installer.compat.driver', 'auto');
            $script = config('registry-bridge.installer.compat.script') ?? rtrim($config->consolePath, '/') . '/node_modules/@fleetbase/ember-core/bin/check-engine-compat.js';
            $node   = config('registry-bridge.installer.binaries.node', 'node');

            if ($driver === 'node' || ($driver === 'auto' && is_file($script) && (new ExecutableFinder())->find($node))) {
                return new NodeEngineCompat($app->make(ProcessRunner::class), $script, $config->consolePath, $node);
            }

            return new PhpEngineCompat();
        });

        $this->app->bind(ArtifactDownloader::class, function (Application $app) {
            return new ArtifactDownloader(
                $app->make(InstallerConfig::class)->requireChecksums,
                fn (string $disk, string $path) => Storage::disk($disk)->readStream($path),
            );
        });

        $this->app->bind(RuntimeEngineManifest::class, function (Application $app) {
            $config = $app->make(InstallerConfig::class);

            return new RuntimeEngineManifest($config->runtimeManifestFile(), $config->runtimeEnginesBaseUrl);
        });

        $this->app->singleton(InstallerJobStore::class, fn () => new InstallerJobStore(static::installerCache(), (int) config('registry-bridge.installer.job_ttl', 604800)));

        $this->app->singleton(InstallerQueue::class, function (Application $app) {
            $store = static::installerCache()->getStore();
            if (!$store instanceof LockProvider) {
                throw new \RuntimeException('The installer needs a cache store with locks (redis, database, file); set REGISTRY_INSTALLER_CACHE_STORE.');
            }

            return new InstallerQueue(
                $app->make(InstallerJobStore::class),
                $store,
                $app->make(Dispatcher::class),
                (string) config('registry-bridge.installer.queue', 'installer'),
                config('registry-bridge.installer.connection'),
                (int) config('registry-bridge.installer.lock_seconds', 10800),
                (int) config('registry-bridge.installer.retry_delay', 15),
                (int) config('registry-bridge.installer.stale_after', 1800),
                (int) config('registry-bridge.installer.timeout', 7200),
            );
        });

        $this->app->bind(InstallerService::class, function (Application $app) {
            return new InstallerService(
                $app->make(InstallerQueue::class),
                $app->make(InstallerJobStore::class),
                $app->make(ReleaseSource::class),
                $app->make(InstallRecorder::class),
                $app->make(\Fleetbase\RegistryBridge\Support\Installer\ExtensionInstaller::class),
                config('registry-bridge.extensions.preinstalled') === true,
                $app->make(InstallReporter::class),
            );
        });
    }

    /**
     * The cache store for installer jobs and the instance lock. The null store
     * would silently drop job records, so it falls back to the file store.
     */
    protected static function installerCache(): \Illuminate\Contracts\Cache\Repository
    {
        $cache = Cache::store(config('registry-bridge.installer.cache_store'));

        return $cache->getStore() instanceof NullStore ? Cache::store('file') : $cache;
    }

    /**
     * Bootstrap any package services.
     *
     * @return void
     *
     * @throws \Exception if the `fleetbase/core-api` package is not installed
     */
    public function boot()
    {
        $this->registerCommands();
        $this->registerMiddleware();
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->loadMigrationsFrom(__DIR__ . '/../../migrations');
        $this->mergeConfigFrom(__DIR__ . '/../../config/registry-bridge.php', 'registry-bridge');
        $this->mergeConfigFromSettings();
    }

    /**
     * Merge configuration settings from the database into the application configuration.
     *
     * This function checks if there is a database connection available. If a connection exists,
     * it retrieves the registry settings (legacy host and token, Registry Service URL, API version,
     * the encrypted instance token and the registered instance) from the settings table and merges
     * them into the application's configuration. The instance token is decrypted with the app key;
     * a token that cannot be decrypted is ignored. If the settings are not available or the database
     * connection does not exist, the function will return without making any changes.
     *
     * @return void
     */
    public function mergeConfigFromSettings()
    {
        if (Setting::doesntHaveConnection()) {
            return;
        }

        $serviceUrl = Setting::getByKey(InstanceRegistration::SETTING_SERVICE_URL);
        if ($serviceUrl && is_string($serviceUrl->value) && $serviceUrl->value !== '') {
            config(['registry-bridge.registry.service_url' => $serviceUrl->value]);
        }

        $instanceToken = Setting::getByKey(InstanceRegistration::SETTING_INSTANCE_TOKEN);
        if ($instanceToken) {
            /** @var \Illuminate\Contracts\Encryption\StringEncrypter $encrypter */
            $encrypter = $this->app->make('encrypter');
            $token     = InstanceRegistration::decryptToken($instanceToken->value, $encrypter);
            if ($token) {
                config(['registry-bridge.registry.instance_token' => $token]);
            }
        }

        $instance = Setting::getByKey(InstanceRegistration::SETTING_INSTANCE);
        if ($instance && is_array($instance->value)) {
            config(['registry-bridge.registry.instance' => $instance->value]);
        }
    }
}
