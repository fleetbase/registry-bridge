<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Registry Bridge routes
|--------------------------------------------------------------------------
|
| The console's Extensions module talks to the Registry Service through these routes: the catalog,
| this instance's installs and licences, the installer and the service checkout. Publishing, review
| and payouts live on the publisher website.
|
*/
$prefix = config('registry-bridge.api.routing.prefix', '~registry');

// Runtime engines manifest: read by the console at boot, before sign-in
Route::get($prefix . '/v1/engines/runtime', 'Fleetbase\RegistryBridge\Http\Controllers\Internal\v1\RuntimeEngineController@index');

Route::prefix($prefix)->middleware(['fleetbase.registry'])->namespace('Fleetbase\RegistryBridge\Http\Controllers')->group(
    function ($router) {
        $router->group(['prefix' => config('registry-bridge.api.routing.internal_prefix', 'v1'), 'namespace' => 'Internal\v1', 'middleware' => ['fleetbase.protected']], function ($router) {
            $router->get('categories', 'RegistryController@categories');
            $router->get('engines', 'RegistryController@getInstalledEngines');
            $router->get('engine-install-status', 'RegistryController@getEngineInstallStatus');

            $router->group(['prefix' => 'installer'], function ($router) {
                $router->post('install', 'ExtensionInstallerController@install');
                $router->post('uninstall', 'ExtensionInstallerController@uninstall');
                $router->post('update', 'ExtensionInstallerController@update');
                $router->get('jobs/{id}', 'ExtensionInstallerController@job');
            });

            $router->group(['prefix' => 'purchases'], function ($router) {
                $router->post('checkout', 'ServicePurchaseController@checkout');
                $router->get('{handoff}', 'ServicePurchaseController@handoff');
            });

            $router->group(['prefix' => 'registry-extensions'], function ($router) {
                $router->get('/', 'RegistryExtensionController@query');
                $router->get('context', 'RegistryExtensionController@context');
                $router->get('counts', 'RegistryExtensionController@counts');
                $router->get('installed', 'RegistryExtensionController@installed');
                $router->get('purchased', 'RegistryExtensionController@purchased');
                $router->get('config', 'RegistryExtensionController@getConfig');
                $router->post('config', 'RegistryExtensionController@saveConfig');
                $router->post('config/test', 'RegistryExtensionController@testConfig');
                $router->post('instance/register', 'RegistryExtensionController@registerInstance');
                $router->delete('instance', 'RegistryExtensionController@unregisterInstance');
                $router->get('{id}/releases', 'RegistryExtensionController@releases');
                $router->get('{id}', 'RegistryExtensionController@find');
            });
        });
    }
);
