<?php

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
        ->and($mapped['current_bundle_id'])->toBe('rel_gjrc6mixp0')
        ->and($mapped['payment_required'])->toBeTrue()
        ->and($mapped['price'])->toBe(4900)
        ->and($mapped['currency'])->toBe('USD')
        ->and($mapped['languages'])->toBe(['en'])
        ->and($mapped['self_managed'])->toBeFalse()
        ->and($mapped['source'])->toBe('registry-service')
        ->and($mapped['meta']['package_names'])->toBe(['npm' => '@registry-demo/pro-engine', 'composer' => 'registry-demo/pro-api'])
        ->and($mapped['meta']['registry_host'])->toBe(RegistryServiceFake::SERVICE_URL)
        ->and($mapped['meta']['versions'])->toHaveCount(1);
});

test('every recorded listing maps with the keys the console reads', function () {
    $keys = array_keys(Fleetbase\RegistryBridge\Support\CentralCatalog::transform(['id' => 'x', 'name' => 'x']));

    foreach (RegistryServiceFake::body('catalog-extensions')['data'] as $listing) {
        expect(array_keys(ServiceCatalog::transform($listing)))->toContain(...$keys);
    }
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

test('Explore passes search, category and hosting to the service and decorates the page', function () {
    $this->service
        ->on('GET', 'v1/catalog/extensions', 'catalog-extensions')
        ->on('GET', 'v1/instances/me', 'instances-me-installed')
        ->on('GET', 'v1/instances/me/updates', 'instances-me-updates');
    $client = RegistryClient::fromConfig(RegistryServiceFake::config());

    $page = ServiceCatalog::query(Request::create('/registry-extensions', 'GET', ['explore' => 1, 'query' => 'demo', 'category' => 'cat_3a9uznxr8s', 'limit' => 10]), $client, RegistryServiceFake::config());

    $url = $this->service->requestsTo('GET', 'v1/catalog/extensions')[0]->url();
    expect($url)->toContain('q=demo')
        ->and($url)->toContain('category=cat_3a9uznxr8s')
        ->and($url)->toContain('hosting=self_hosted')
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

test('Purchased lists only active paid licences', function () {
    $this->service
        ->on('GET', 'v1/catalog/extensions', 'catalog-extensions')
        ->on('GET', 'v1/instances/me', 'instances-me-installed')
        ->on('GET', 'v1/instances/me/updates', 'instances-me-updates');

    $purchased = ServiceCatalog::purchased(RegistryClient::fromConfig(RegistryServiceFake::config()), RegistryServiceFake::config());

    expect(array_column($purchased, 'slug'))->toBe(['registry-demo-pro'])
        ->and($purchased[0]['is_purchased'])->toBeTrue();
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
