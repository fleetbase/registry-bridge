<?php

namespace Fleetbase\RegistryBridge\Providers;

use Fleetbase\Models\Setting;
use Fleetbase\Providers\CoreServiceProvider;

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
    }

    /**
     * Merge configuration settings from the database into the application configuration.
     *
     * This function checks if there is a database connection available. If a connection exists,
     * it retrieves the 'registry-bridge.registry.host' and 'registry-bridge.registry.token' values
     * from the settings table and merges them into the application's configuration. If the settings
     * are not available or the database connection does not exist, the function will return without
     * making any changes.
     *
     * @return void
     */
    public function mergeConfigFromSettings()
    {
        if (Setting::doesntHaveConnection()) {
            return;
        }

        $registryHost  = Setting::getByKey('registry-bridge.registry.host');
        $registryToken = Setting::getByKey('registry-bridge.registry.token');

        if ($registryHost) {
            config(['registry-bridge.registry.host' => $registryHost->value]);
        }

        if ($registryToken) {
            config(['registry-bridge.registry.token' => $registryToken->value]);
        }
    }
}
