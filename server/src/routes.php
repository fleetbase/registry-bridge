<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
// Public endpoints (no authentication required)
Route::get(config('registry-bridge.api.routing.prefix', '~registry') . '/v1/extensions', 'Fleetbase\RegistryBridge\Http\Controllers\Internal\v1\RegistryExtensionController@listPublicExtensions');
Route::get(config('registry-bridge.api.routing.prefix', '~registry') . '/v1/lookup', 'Fleetbase\RegistryBridge\Http\Controllers\Internal\v1\RegistryController@lookupPackage');
Route::post(config('registry-bridge.api.routing.prefix', '~registry') . '/v1/bundle-upload', 'Fleetbase\RegistryBridge\Http\Controllers\Internal\v1\RegistryController@bundleUpload');
Route::prefix(config('registry-bridge.api.routing.prefix', '~registry'))->middleware(['fleetbase.registry'])->namespace('Fleetbase\RegistryBridge\Http\Controllers')->group(
    function ($router) {
        /*
         * Internal Routes v1
         */
        $router->group(['prefix' => config('registry-bridge.api.routing.internal_prefix', 'v1'), 'namespace' => 'Internal\v1'], function ($router) {
            $router->group(['prefix' => 'auth'], function ($router) {
                $router->group(['middleware' => ['fleetbase.protected']], function ($router) {
                    $router->get('registry-tokens', 'RegistryAuthController@getRegistryTokens');
                    $router->delete('registry-tokens/{id}', 'RegistryAuthController@deleteRegistryToken');
                    $router->post('registry-tokens', 'RegistryAuthController@createRegistryUser');
                });

                $router->post('composer-auth', 'RegistryAuthController@composerAuthentication');
                $router->post('authenticate', 'RegistryAuthController@authenticate');
                $router->post('add-user', 'RegistryAuthController@addUser');
                $router->post('check-access', 'RegistryAuthController@checkAccess');
                $router->post('check-publish', 'RegistryAuthController@checkPublishAllowed');
            });

            $router->group(['prefix' => 'developer-account'], function ($router) {
                $router->post('register', 'RegistryDeveloperAccountController@register');
                $router->post('verify', 'RegistryDeveloperAccountController@verifyEmail');
                $router->post('resend-verification', 'RegistryDeveloperAccountController@resendVerification');
                $router->get('profile', 'RegistryDeveloperAccountController@profile');
                $router->post('profile', 'RegistryDeveloperAccountController@updateProfile');
            });

            $router->group(['middleware' => ['fleetbase.protected']], function ($router) {
                $router->get('categories', 'RegistryController@categories');
                $router->get('engines', 'RegistryController@getInstalledEngines');
                $router->get('engine-install-status', 'RegistryController@getEngineInstallStatus');

                $router->group(['prefix' => 'installer'], function ($router) {
                    $router->post('install', 'ExtensionInstallerController@install');
                    $router->post('uninstall', 'ExtensionInstallerController@uninstall');
                });

                $router->group(['prefix' => 'payments'], function ($router) {
                    $router->post('account', 'RegistryPaymentsController@getStripeAccount');
                    $router->post('account-session', 'RegistryPaymentsController@getStripeAccountSession');
                    $router->post('account-management-session', 'RegistryPaymentsController@createAccountManagementSession');
                    $router->get('has-stripe-connect-account', 'RegistryPaymentsController@hasStripeConnectAccount');
                    $router->post('create-checkout-session', 'RegistryPaymentsController@createStripeCheckoutSession');
                    $router->post('get-checkout-session', 'RegistryPaymentsController@getStripeCheckoutSessionStatus');
                    $router->get('author-received', 'RegistryPaymentsController@getAuthorReceivedPayments');
                });

                $router->fleetbaseRoutes('registry-extensions', function ($router, $controller) {
                    $router->post('{id}/submit', $controller('submit'));
                    $router->post('approve', $controller('approve'));
                    $router->post('reject', $controller('reject'));
                    $router->post('publish', $controller('manualPublish'));
                    $router->get('download-bundle', $controller('downloadBundle'));
                    $router->get('analytics', $controller('analytics'));
                    $router->get('installed', $controller('installed'));
                    $router->get('purchased', $controller('purchased'));
                    $router->get('config', $controller('getConfig'));
                    $router->post('config', $controller('saveConfig'));
                });

                $router->fleetbaseRoutes('registry-extension-bundles', function ($router, $controller) {
                    $router->get('download', $controller('download'));
                });
            });
        });
    }
);
