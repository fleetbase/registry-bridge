<?php

/*
 * Registry data (extensions, bundles, installs, purchases, registry users and
 * developer accounts) only exists in the live database: this package ships with
 * `extra.fleetbase.sandbox-migrations: false`. When core-api switches a request
 * into sandbox ("test mode") it sets `database.default` and
 * `fleetbase.connection.db` to `sandbox`, and every Fleetbase model that is not
 * pinned follows it. These tests prove the registry models and the query paths
 * behind the console endpoints stay on the live `mysql` connection.
 *
 * The harness uses two independent in-memory SQLite databases named `mysql` and
 * `sandbox`. The registry tables are only created on `mysql`, exactly like
 * production, so any query that leaks onto the sandbox connection fails with
 * "no such table".
 */

use Fleetbase\Models\Category;
use Fleetbase\RegistryBridge\Expansions\CategoryExpansion;
use Fleetbase\RegistryBridge\Http\Controllers\Internal\v1\RegistryController;
use Fleetbase\RegistryBridge\Http\Controllers\Internal\v1\RegistryExtensionController;
use Fleetbase\RegistryBridge\Models\RegistryExtension;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;

const LIVE_CONNECTION    = 'mysql';
const SANDBOX_CONNECTION = 'sandbox';
const TEST_COMPANY_UUID  = '00000000-0000-4000-8000-000000000001';

/**
 * Every Eloquent model class shipped in server/src/Models.
 *
 * @return array<int, class-string<EloquentModel>>
 */
function registryBridgeModelClasses(): array
{
    $classes = [];
    foreach (glob(__DIR__ . '/../src/Models/*.php') ?: [] as $file) {
        $class = 'Fleetbase\\RegistryBridge\\Models\\' . basename($file, '.php');
        if (class_exists($class) && is_subclass_of($class, EloquentModel::class) && !(new ReflectionClass($class))->isAbstract()) {
            $classes[] = $class;
        }
    }

    return $classes;
}

/**
 * Boot a minimal application with a live and a sandbox connection, then switch
 * the config into sandbox mode the same way `Auth::setSandboxSession()` does.
 */
function bootSandboxHarness(): Capsule
{
    $app = new Application(dirname(__DIR__, 2));
    Container::setInstance($app);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($app);

    $app->instance('config', new Repository([
        'database' => [
            'default'     => LIVE_CONNECTION,
            'connections' => [
                LIVE_CONNECTION    => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
                SANDBOX_CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            ],
        ],
        'fleetbase' => ['connection' => ['db' => LIVE_CONNECTION]],
        // The local registry tables are only read when this instance is itself the registry (cloud)
        'registry-bridge' => ['registry' => ['self_hosted' => true]],
        'cache'           => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
    ]));

    $capsule = new Capsule($app);
    $capsule->addConnection(config('database.connections.' . LIVE_CONNECTION), LIVE_CONNECTION);
    $capsule->addConnection(config('database.connections.' . SANDBOX_CONNECTION), SANDBOX_CONNECTION);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $app->instance('db', $capsule->getDatabaseManager());

    // Only `response()->json()` is used by the code under test, which needs neither views nor redirects.
    $app->instance(ResponseFactoryContract::class, (new ReflectionClass(Illuminate\Routing\ResponseFactory::class))->newInstanceWithoutConstructor());

    $session = new Store('test', new ArraySessionHandler(10));
    $session->put('company', TEST_COMPANY_UUID);
    $app->instance('session', $session);
    $app->instance('session.store', $session);
    $app->instance('request', sandboxRequest());

    // Core categories exist in both databases (core runs sandbox migrations).
    foreach ([LIVE_CONNECTION, SANDBOX_CONNECTION] as $connection) {
        $capsule->schema($connection)->create('categories', function (Blueprint $table) {
            $table->string('uuid')->primary();
            $table->string('public_id')->nullable();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('for')->nullable();
            $table->boolean('core_category')->default(false);
            $table->string('icon_file_uuid')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    // Registry tables only exist on the live connection.
    $schema = $capsule->schema(LIVE_CONNECTION);
    $schema->create('registry_extension_bundles', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('extension_uuid')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('registry_extensions', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->string('status')->nullable();
        $table->string('category_uuid')->nullable();
        $table->string('current_bundle_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('registry_extension_installs', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('extension_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('registry_service_installs', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('extension_id')->nullable();
        $table->string('npm_package')->nullable();
        $table->string('composer_package')->nullable();
        $table->string('version')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('registry_extension_purchases', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('extension_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $live = $capsule->getConnection(LIVE_CONNECTION);
    $live->table('categories')->insert([
        ['uuid' => 'cat-with-extension', 'public_id' => 'category_live1', 'name' => 'Telematics', 'slug' => 'telematics', 'for' => 'extension_category', 'core_category' => 1],
        ['uuid' => 'cat-empty', 'public_id' => 'category_live2', 'name' => 'Empty', 'slug' => 'empty', 'for' => 'extension_category', 'core_category' => 1],
    ]);
    $live->table('registry_extension_bundles')->insert([
        'uuid'           => 'bundle-1',
        'extension_uuid' => 'ext-1',
        'meta'           => json_encode(['package.json' => ['name' => '@fleetbase/test-engine']]),
    ]);
    $live->table('registry_extensions')->insert([
        'uuid'                => 'ext-1',
        'public_id'           => 'extension_1',
        'name'                => 'Test Engine',
        'status'              => 'published',
        'category_uuid'       => 'cat-with-extension',
        'current_bundle_uuid' => 'bundle-1',
    ]);
    $live->table('registry_extension_installs')->insert(['uuid' => 'install-1', 'company_uuid' => TEST_COMPANY_UUID, 'extension_uuid' => 'ext-1']);
    $live->table('registry_extension_purchases')->insert(['uuid' => 'purchase-1', 'company_uuid' => TEST_COMPANY_UUID, 'extension_uuid' => 'ext-1']);

    // The sandbox database has a category of the same kind, which must never be returned.
    $capsule->getConnection(SANDBOX_CONNECTION)->table('categories')->insert([
        'uuid' => 'cat-sandbox', 'public_id' => 'category_sandbox', 'name' => 'Sandbox', 'slug' => 'sandbox', 'for' => 'extension_category', 'core_category' => 1,
    ]);

    Category::expand('registryExtensions', CategoryExpansion::registryExtensions());

    // Switch into sandbox mode exactly like Auth::setSandboxSession().
    config(['database.default' => SANDBOX_CONNECTION, 'fleetbase.connection.db' => SANDBOX_CONNECTION]);
    $capsule->getDatabaseManager()->setDefaultConnection(SANDBOX_CONNECTION);

    return $capsule;
}

function sandboxRequest(): Request
{
    $request = Request::create('/');
    $request->setLaravelSession(app('session'));

    return $request;
}

beforeEach(function () {
    // Core's resource resolution reads the verb straight from $_SERVER.
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $this->capsule             = bootSandboxHarness();
});

afterEach(function () {
    unset($_SERVER['REQUEST_METHOD']);
    Container::setInstance(null);
    Facade::clearResolvedInstances();
});

test('the models directory contains every registry model', function () {
    expect(registryBridgeModelClasses())->toHaveCount(7);
});

test('every registry-bridge model is pinned to the live connection while sandbox is active', function () {
    expect(config('fleetbase.connection.db'))->toBe(SANDBOX_CONNECTION);
    expect((new Category())->getConnectionName())->toBe(SANDBOX_CONNECTION);

    foreach (registryBridgeModelClasses() as $class) {
        $model = new $class();
        expect($model->getConnectionName())->toBe(LIVE_CONNECTION, $class . ' must use the live connection');
        expect($class::query()->getConnection()->getName())->toBe(LIVE_CONNECTION, $class . ' queries must run on the live connection');
    }
});

test('the registry extension category relation resolves against the live connection', function () {
    $extension = RegistryExtension::where('uuid', 'ext-1')->firstOrFail();

    expect($extension->category()->getQuery()->getConnection()->getName())->toBe(LIVE_CONNECTION);
    expect($extension->category)->not->toBeNull();
    expect($extension->category->uuid)->toBe('cat-with-extension');

    $eager = RegistryExtension::with('category')->where('uuid', 'ext-1')->firstOrFail();
    expect($eager->category->uuid)->toBe('cat-with-extension');
});

test('categories endpoint queries live categories and live registry extensions in sandbox mode', function () {
    $response = app(RegistryController::class)->categories();
    $uuids    = collect($response->resource)->pluck('uuid')->all();

    expect($uuids)->toBe(['cat-with-extension']);
    collect($response->resource)->each(fn ($category) => expect($category->getConnectionName())->toBe(LIVE_CONNECTION));
});

test('engines endpoint reads installs from the live connection in sandbox mode', function () {
    $response = app(RegistryController::class)->getInstalledEngines(sandboxRequest());

    expect($response->getData(true))->toBe([['name' => '@fleetbase/test-engine']]);
});

test('engine install status reads from the live connection in sandbox mode', function () {
    $request = sandboxRequest();
    $request->merge(['engine' => '@fleetbase/test-engine']);

    expect(app(RegistryController::class)->getEngineInstallStatus($request))->toBe(['installed' => true]);
});

test('installed and purchased extensions are read from the live connection in sandbox mode', function () {
    $controller = app(RegistryExtensionController::class);

    foreach (['installed', 'purchased'] as $method) {
        $response = $controller->{$method}();
        $models   = collect($response->resource);

        expect($models->pluck('uuid')->all())->toBe(['ext-1'], $method);
        $models->each(fn ($extension) => expect($extension->getConnectionName())->toBe(LIVE_CONNECTION));
    }
});
