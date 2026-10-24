<?php

use Fleetbase\RegistryBridge\Support\Installer\Files;
use Fleetbase\RegistryBridge\Support\Installer\InstallerConfig;
use Fleetbase\RegistryBridge\Support\RegistryClient;
use Fleetbase\RegistryBridge\Support\ServiceCatalog;
use Fleetbase\RegistryBridge\Tests\Support\RegistryServiceFake;
use Illuminate\Http\Request;

function serviceListing(string $slug): array
{
    foreach (RegistryServiceFake::body('catalog-extensions')['data'] as $listing) {
        if ($listing['slug'] === $slug) {
            return $listing;
        }
    }

    throw new RuntimeException('No recorded listing ' . $slug);
}

function registeredState(): array
{
    return ServiceCatalog::buildState(
        RegistryServiceFake::body('instances-me-installed'),
        RegistryServiceFake::body('instances-me-updates'),
        [],
        true
    );
}

beforeEach(function () {
    $this->service = new RegistryServiceFake();
});

test('a listing maps into the RegistryExtension resource shape', function () {
    $mapped = ServiceCatalog::transform(RegistryServiceFake::body('catalog-extension'), [], RegistryServiceFake::SERVICE_URL);

    expect($mapped['id'])->toBe('ext_gzh4wgc9nr')
        ->and($mapped['uuid'])->toBe('ext_gzh4wgc9nr')
        ->and($mapped['public_id'])->toBe('ext_gzh4wgc9nr')
        ->and($mapped['slug'])->toBe('registry-demo-pro')
        ->and($mapped['description'])->toBe('Demo listing used to exercise the npm and Composer endpoints.')
        ->and($mapped['publisher_name'])->toBe('Registry Demo')
        ->and($mapped['category_name'])->toBe('Telematics')
        ->and($mapped['category_uuid'])->toBe('cat_3a9uznxr8s')
        ->and($mapped['current_bundle_version'])->toBe('1.0.0')
        ->and($mapped['meta']['latest_release']['id'])->toBe('rel_gjrc6mixp0')
        ->and($mapped['payment_required'])->toBeTrue()
        ->and($mapped['price'])->toBe(4900)
        ->and($mapped['currency'])->toBe('USD')
        ->and($mapped['languages'])->toBe(['en'])
        ->and($mapped['source'])->toBe('registry-service')
        ->and($mapped['meta']['package_names'])->toBe(['npm' => '@registry-demo/pro-engine', 'composer' => 'registry-demo/pro-api'])
        ->and($mapped['meta']['registry_host'])->toBe(RegistryServiceFake::SERVICE_URL)
        ->and($mapped['meta']['versions'])->toHaveCount(1);
});

test('every recorded listing maps with the keys the console reads', function () {
    $keys = [
        'id', 'public_id', 'name', 'subtitle', 'description', 'promotional_text', 'icon_url', 'slug', 'status', 'tags', 'languages',
        'website_url', 'repo_url', 'support_url', 'privacy_policy_url', 'tos_url', 'copyright', 'payment_required', 'price', 'sale_price',
        'on_sale', 'currency', 'category', 'category_name', 'publisher_name', 'current_bundle_version', 'screenshots', 'is_installed',
        'is_purchased', 'is_author', 'is_installable', 'installed_version', 'latest_version', 'update_available', 'source', 'meta',
        'core_extension', 'installs_count', 'updated_at',
    ];

    foreach (RegistryServiceFake::body('catalog-extensions')['data'] as $listing) {
        expect(array_keys(ServiceCatalog::transform($listing)))->toContain(...$keys);
    }
});

test('install mode: runtime only for a prebuilt dist this console can load, otherwise a rebuild', function () {
    $dist    = ['sha256' => str_repeat('a', 64), 'built_against' => ['@fleetbase/ember-ui' => '0.4.6', '@fleetbase/ember-core' => '0.3.26']];
    $console = ['host_versions' => ['@fleetbase/ember-ui' => '0.4.9', '@fleetbase/ember-core' => '0.3.30'], 'built_in' => ['@fleetbase/ember-core']];

    expect(ServiceCatalog::installMode('@acme/a-engine', $dist, $console))->toBe('runtime')
        ->and(ServiceCatalog::installMode('@acme/a-engine', $dist, null))->toBe('runtime')
        ->and(ServiceCatalog::installMode(null, null, $console))->toBe('server')
        ->and(ServiceCatalog::installMode('@acme/a-engine', null, $console))->toBe('build')
        ->and(ServiceCatalog::installMode('@acme/a-engine', ['sha256' => ''], $console))->toBe('build')
        ->and(ServiceCatalog::installMode('@acme/a-engine', $dist, ['host_versions' => null, 'built_in' => []]))->toBe('build')
        ->and(ServiceCatalog::installMode('@acme/a-engine', $dist, [...$console, 'built_in' => ['@acme/a-engine']]))->toBe('build')
        ->and(ServiceCatalog::installMode('@acme/a-engine', $dist, [...$console, 'host_versions' => ['@fleetbase/ember-ui' => '0.5.0']]))->toBe('build');
});

test('the console state comes from its host-modules.json and package.json', function () {
    $root = sys_get_temp_dir() . '/registry-console-state-' . bin2hex(random_bytes(4));
    Files::write($root . '/package.json', json_encode(['dependencies' => ['@acme/built-engine' => '1.0.0'], 'devDependencies' => ['ember-source' => '5.4.0']]));
    $config = InstallerConfig::fromArray(['api_path' => $root . '/api', 'console_path' => $root, 'work_path' => $root . '/work']);

    expect(ServiceCatalog::consoleState($config))->toBe(['host_versions' => null, 'built_in' => ['@acme/built-engine', 'ember-source']]);

    Files::write($root . '/dist/host-modules.json', json_encode(['versions' => ['@fleetbase/ember-ui' => '0.4.6'], 'modules' => []]));
    expect(ServiceCatalog::consoleState($config)['host_versions'])->toBe(['@fleetbase/ember-ui' => '0.4.6']);

    Files::delete($root);
});

test('listings carry the install mode of the release an install or update would take', function () {
    $console = ['host_versions' => ['@fleetbase/ember-ui' => '0.4.6', '@fleetbase/ember-core' => '0.3.26'], 'built_in' => []];
    $dist    = ['sha256' => str_repeat('b', 64), 'built_against' => ['@fleetbase/ember-ui' => '0.4.6']];
    $listing = serviceListing('registry-demo-hello');

    expect(ServiceCatalog::transform($listing, ['console' => $console])['meta']['install_mode'])->toBe('build');

    $listing['latest_release']['dist'] = $dist;
    $mapped                            = ServiceCatalog::transform($listing, ['console' => $console]);
    expect($mapped['meta']['install_mode'])->toBe('runtime')
        ->and($mapped['meta']['uninstall_mode'])->toBe('runtime')
        ->and($mapped['meta']['latest_release']['dist'])->toBe($dist);

    // an installed extension: the update's release decides, and an engine built into the console uninstalls by rebuild
    $state            = registeredState();
    $state['console'] = [...$console, 'built_in' => [$listing['packages']['npm']]];
    $mapped           = ServiceCatalog::transform($listing, $state);
    expect($mapped['update_available'])->toBeTrue()
        ->and($mapped['meta']['install_mode'])->toBe('build')
        ->and($mapped['meta']['uninstall_mode'])->toBe('build');
});

test('an installed free extension shows its update from the instance API', function () {
    $mapped = ServiceCatalog::transform(serviceListing('registry-demo-hello'), registeredState());

    expect($mapped['is_installed'])->toBeTrue()
        ->and($mapped['is_purchased'])->toBeFalse()
        ->and($mapped['is_installable'])->toBeTrue()
        ->and($mapped['installed_version'])->toBe('1.0.0')
        ->and($mapped['latest_version'])->toBe('1.1.0')
        ->and($mapped['update_available'])->toBeTrue()
        ->and($mapped['meta']['licence']['kind'])->toBe('free');
});

test('a paid licence held by the organization marks the extension purchased', function () {
    $mapped = ServiceCatalog::transform(serviceListing('registry-demo-pro'), registeredState());

    expect($mapped['is_purchased'])->toBeTrue()
        ->and($mapped['is_installed'])->toBeFalse()
        ->and($mapped['update_available'])->toBeFalse()
        ->and($mapped['is_installable'])->toBeTrue()
        ->and($mapped['meta']['install_blocked_reason'])->toBeNull();
});

test('a paid extension without a licence is listed but not installable', function () {
    $state             = ServiceCatalog::buildState(RegistryServiceFake::body('instances-me'), [], [], true);
    $state['licences'] = [];
    $mapped            = ServiceCatalog::transform(serviceListing('registry-demo-pro'), $state);

    expect($mapped['is_purchased'])->toBeFalse()
        ->and($mapped['payment_required'])->toBeTrue()
        ->and($mapped['is_installable'])->toBeFalse()
        ->and($mapped['meta']['install_blocked_reason'])->toContain('does not hold a licence');
});

test('an unregistered instance can browse but not install', function () {
    $mapped = ServiceCatalog::transform(serviceListing('registry-demo-hello'), ServiceCatalog::buildState());

    expect($mapped['is_installed'])->toBeFalse()
        ->and($mapped['is_installable'])->toBeFalse()
        ->and($mapped['meta']['install_blocked_reason'])->toBe('Register this instance with the registry service to install extensions.');
});

test('hosting that excludes the instance kind blocks the install', function () {
    $listing            = serviceListing('registry-demo-hello');
    $listing['hosting'] = 'cloud';

    expect(ServiceCatalog::transform($listing, registeredState())['meta']['install_blocked_reason'])->toBe('This extension is only available on Fleetbase Cloud.');
});

test('local install records mark an extension installed by package name or id', function () {
    $byPackage = ServiceCatalog::transform(serviceListing('registry-demo-pro'), ServiceCatalog::buildState([], [], ['registry-demo/pro-api'], true));
    $byId      = ServiceCatalog::transform(serviceListing('registry-demo-pro'), ServiceCatalog::buildState([], [], ['ext_gzh4wgc9nr'], true));

    expect($byPackage['is_installed'])->toBeTrue()
        ->and($byId['is_installed'])->toBeTrue()
        ->and($byPackage['update_available'])->toBeFalse();
});

test('categories map into the Category resource shape', function () {
    $this->service->on('GET', 'v1/catalog/categories', 'catalog-categories');

    $categories = ServiceCatalog::categories(RegistryClient::fromConfig(RegistryServiceFake::config()));

    expect($categories[0])->toMatchArray([
        'id'            => 'cat_3a9uznxr8s',
        'name'          => 'Telematics',
        'slug'          => 'telematics',
        'icon'          => 'satellite-dish',
        'for'           => 'extension_category',
        'core_category' => 1,
    ])->and($categories[0]['meta']['extensions_count'])->toBe(2);
});

test('Explore passes search and category to the service, lists every hosting, and decorates the page', function () {
    $this->service
        ->on('GET', 'v1/catalog/extensions', 'catalog-extensions')
        ->on('GET', 'v1/instances/me', 'instances-me-installed')
        ->on('GET', 'v1/instances/me/updates', 'instances-me-updates');
    $client = RegistryClient::fromConfig(RegistryServiceFake::config());

    $page = ServiceCatalog::query(Request::create('/registry-extensions', 'GET', ['explore' => 1, 'query' => 'demo', 'category' => 'cat_3a9uznxr8s', 'limit' => 10]), $client, RegistryServiceFake::config());

    $url = $this->service->requestsTo('GET', 'v1/catalog/extensions')[0]->url();
    expect($url)->toContain('q=demo')
        ->and($url)->toContain('category=cat_3a9uznxr8s')
        ->and($url)->not->toContain('hosting=')
        ->and($url)->toContain('limit=10')
        ->and($page->total())->toBe(2)
        ->and($page->items()[0]['is_installed'])->toBeTrue()
        ->and($page->items()[0]['update_available'])->toBeTrue()
        ->and($page->items()[1]['is_purchased'])->toBeTrue();
});

test('Explore still lists the catalog when the instance API is unreachable', function () {
    $this->service
        ->on('GET', 'v1/catalog/extensions', 'catalog-extensions')
        ->on('GET', 'v1/instances/me', 'instances-me-unauthenticated');
    $client = RegistryClient::fromConfig(RegistryServiceFake::config());

    $page = ServiceCatalog::query(Request::create('/registry-extensions', 'GET', ['explore' => 1]), $client, RegistryServiceFake::config());

    expect($page->items())->toHaveCount(2)
        ->and($page->items()[0]['is_installed'])->toBeFalse();
});

test('Installed joins the instance updates and local records with the catalog', function () {
    $this->service
        ->on('GET', 'v1/catalog/extensions', 'catalog-extensions')
        ->on('GET', 'v1/instances/me', 'instances-me-installed')
        ->on('GET', 'v1/instances/me/updates', 'instances-me-updates');
    $client = RegistryClient::fromConfig(RegistryServiceFake::config());

    $installed = ServiceCatalog::installed($client, RegistryServiceFake::config(), [
        ['extension_id' => 'ext_lx52k33erf', 'slug' => 'registry-demo-hello', 'npm_package' => '@registry-demo/hello-engine', 'version' => '1.0.0'],
        ['extension_id' => 'ext_gone', 'slug' => 'gone', 'name' => 'Removed Listing', 'npm_package' => '@acme/gone-engine', 'version' => '0.1.0'],
    ]);

    expect(array_column($installed, 'id'))->toBe(['ext_lx52k33erf', 'ext_gone'])
        ->and($installed[0]['name'])->toBe('Registry Demo Hello')
        ->and($installed[0]['update_available'])->toBeTrue()
        ->and($installed[1]['name'])->toBe('Removed Listing')
        ->and($installed[1]['is_installed'])->toBeTrue()
        ->and($installed[1]['status'])->toBe('unlisted')
        ->and($installed[1]['current_bundle_version'])->toBe('0.1.0');
});

test('Purchased leaves out free licences', function () {
    $this->service
        ->on('GET', 'v1/catalog/extensions', 'catalog-extensions')
        ->on('GET', 'v1/instances/me', 'instances-me-installed')
        ->on('GET', 'v1/instances/me/updates', 'instances-me-updates');

    $purchased = ServiceCatalog::purchased(RegistryClient::fromConfig(RegistryServiceFake::config()), RegistryServiceFake::config());

    expect(array_column($purchased, 'slug'))->toBe(['registry-demo-pro'])
        ->and($purchased[0]['is_purchased'])->toBeTrue();
});

test('Purchased keeps refunded and revoked paid licences with their state, without entitling installs', function () {
    $me                                  = RegistryServiceFake::body('instances-me-installed');
    $me['licences'][1]['status']         = 'revoked';
    $me['licences'][1]['revoked_reason'] = 'refunded';
    $me['licences'][]                    = ['id' => 'lic_gone', 'extension' => ['id' => 'ext_delisted', 'slug' => 'delisted-pro', 'name' => 'Delisted Pro'], 'kind' => 'paid', 'status' => 'revoked', 'revoked_reason' => 'disputed', 'max_activations' => 5, 'seats_used' => 1, 'created_at' => '2026-10-01T00:00:00Z'];
    $this->service
        ->on('GET', 'v1/catalog/extensions', 'catalog-extensions')
        ->respondWith('GET', 'v1/instances/me', 200, $me)
        ->on('GET', 'v1/instances/me/updates', 'instances-me-updates');

    $purchased = ServiceCatalog::purchased(RegistryClient::fromConfig(RegistryServiceFake::config()), RegistryServiceFake::config());
    $bySlug    = array_column($purchased, null, 'slug');

    expect(array_keys($bySlug))->toBe(['registry-demo-pro', 'delisted-pro'])
        ->and($bySlug['registry-demo-pro']['is_purchased'])->toBeFalse()
        ->and($bySlug['registry-demo-pro']['is_installable'])->toBeFalse()
        ->and($bySlug['registry-demo-pro']['meta']['install_blocked_code'])->toBe('licence_required')
        ->and($bySlug['registry-demo-pro']['meta']['licence_state'])->toBe('refunded')
        ->and($bySlug['registry-demo-pro']['meta']['licence']['revoked_reason'])->toBe('refunded')
        ->and($bySlug['delisted-pro']['meta']['licence_state'])->toBe('revoked')
        ->and($bySlug['delisted-pro']['is_purchased'])->toBeFalse();

    $state = ServiceCatalog::buildState($me, [], [], true);
    expect(ServiceCatalog::counts($state)['purchased'])->toBe(0);
});

test('an active paid licence wins over a revoked one for the same extension, in either order', function () {
    $active  = ['id' => 'lic_new', 'extension' => ['id' => 'ext_1'], 'kind' => 'paid', 'status' => 'active'];
    $revoked = ['id' => 'lic_old', 'extension' => ['id' => 'ext_1'], 'kind' => 'paid', 'status' => 'revoked', 'revoked_reason' => 'refunded'];
    $free    = ['id' => 'lic_free', 'extension' => ['id' => 'ext_1'], 'kind' => 'free', 'status' => 'active'];

    foreach ([[$revoked, $active], [$active, $revoked], [$free, $revoked, $active]] as $licences) {
        $state = ServiceCatalog::buildState(['licences' => $licences], [], [], true);
        expect($state['licences']['ext_1']['id'])->toBe('lic_new')
            ->and(ServiceCatalog::counts($state)['purchased'])->toBe(1);
    }

    expect(ServiceCatalog::buildState(['licences' => [$free, $revoked]], [], [], true)['licences']['ext_1']['id'])->toBe('lic_old');
});

test('licence states the console shows', function () {
    expect(ServiceCatalog::licenceState(null))->toBeNull()
        ->and(ServiceCatalog::licenceState(['kind' => 'paid', 'status' => 'active']))->toBe('active')
        ->and(ServiceCatalog::licenceState(['kind' => 'paid', 'status' => 'revoked', 'revoked_reason' => 'refunded']))->toBe('refunded')
        ->and(ServiceCatalog::licenceState(['kind' => 'paid', 'status' => 'revoked', 'revoked_reason' => 'staff']))->toBe('revoked')
        ->and(ServiceCatalog::licenceState(['kind' => 'paid', 'status' => 'revoked']))->toBe('revoked')
        ->and(ServiceCatalog::isPaidLicence(['kind' => 'paid', 'status' => 'revoked', 'revoked_reason' => 'refunded']))->toBeFalse();
});

test('the registry organization comes from the registration, not the console company', function () {
    $config = RegistryServiceFake::config(['instance' => ['id' => 'inst_1', 'organization' => ['id' => 'org_9sz9sh8ivz', 'slug' => 'registry-demo-buyer', 'name' => 'Registry Demo Buyer']]]);

    expect($config->organization())->toBe(['id' => 'org_9sz9sh8ivz', 'slug' => 'registry-demo-buyer', 'name' => 'Registry Demo Buyer'])
        ->and(RegistryServiceFake::config()->organization())->toBeNull()
        ->and(RegistryServiceFake::config(['instance' => ['organization' => ['name' => '']]])->organization())->toBeNull();
});

test('lookup resolves npm and Composer names, slugs and ids from the catalog', function () {
    $this->service->on('GET', 'v1/catalog/extensions', 'catalog-extensions');
    $client = RegistryClient::fromConfig(RegistryServiceFake::config());

    foreach (['@registry-demo/pro-engine', 'registry-demo/pro-api', 'registry-demo-pro', 'ext_gzh4wgc9nr'] as $name) {
        $resolved = ServiceCatalog::lookup($name, $client);
        expect($resolved['npm'])->toBe('@registry-demo/pro-engine')
            ->and($resolved['composer'])->toBe('registry-demo/pro-api')
            ->and($resolved['extension']['slug'])->toBe('registry-demo-pro');
    }

    $this->service->on('GET', 'v1/catalog/extensions/unknown', 'catalog-extension-not-found');
    expect(ServiceCatalog::lookup('@acme/unknown-engine', $client))->toBeNull()
        ->and(ServiceCatalog::lookup('unknown', $client))->toBeNull();
});
