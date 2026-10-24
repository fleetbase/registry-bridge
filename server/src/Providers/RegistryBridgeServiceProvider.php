<?php

namespace Fleetbase\RegistryBridge\Providers;

use Fleetbase\Models\Setting;
use Fleetbase\Providers\CoreServiceProvider;
use Fleetbase\RegistryBridge\Support\RegistrySettings;
use Illuminate\Queue\Events\JobProcessing;

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
    ];

    /**
     * The middleware groups registered with the service provider.
     *
     * @var array
     */
    public $middleware = [
        'fleetbase.registry' => [
            \Illuminate\Session\Middleware\StartSession::class,
            \Fleetbase\Http\Middleware\AuthenticateOnceWithBasicAuth::class,
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
        $this->registerExpansionsFrom(__DIR__ . '/../Expansions');
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->loadMigrationsFrom(__DIR__ . '/../../migrations');
        $this->mergeConfigFrom(__DIR__ . '/../../config/registry-bridge.php', 'registry-bridge');
        $this->mergeConfigFromSettings();
        $this->refreshSettingsPerOperation();
    }

    /**
     * Merge configuration settings from the database into the application configuration.
     *
     * The registry settings (legacy host and token, Registry Service URL, API version, the encrypted instance
     * token and the registered instance) override the config file and environment; see `RegistrySettings`.
     * Without a database connection (installs, migrations) the config is left as it is.
     *
     * Long-lived workers read the settings again for each Octane request and each queued job, on first use
     * through `RegistryConfig::current()`, so a registration made by `registry:register` or by another worker
     * takes effect without `octane:reload`.
     *
     * @return void
     */
    public function mergeConfigFromSettings()
    {
        $settings = RegistrySettings::instance();
        if (Setting::doesntHaveConnection()) {
            $settings->markApplied();

            return;
        }

        $settings->apply();
    }

    /**
     * Read the registry settings again at the start of every Octane request and queued job, and forget the cached
     * settings whenever one of them is written or deleted through the `Setting` model.
     *
     * @return void
     */
    public function refreshSettingsPerOperation()
    {
        $events = $this->app['events'];

        if (class_exists(Setting::class)) {
            Setting::saved(fn ($setting) => RegistrySettings::settingChanged($setting->key ?? null));
            Setting::deleted(fn ($setting) => RegistrySettings::settingChanged($setting->key ?? null));
        }

        $events->listen('Laravel\Octane\Events\RequestReceived', function ($event) {
            RegistrySettings::instance()->reset($event->sandbox ?? null);
        });

        $events->listen(JobProcessing::class, function () {
            RegistrySettings::instance()->reset();
        });
    }
}
