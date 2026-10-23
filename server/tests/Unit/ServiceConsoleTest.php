<?php

use Fleetbase\RegistryBridge\Support\RegistryClient;
use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Fleetbase\RegistryBridge\Support\ServiceCatalog;
use Fleetbase\RegistryBridge\Tests\Support\RegistryServiceFake;
use Illuminate\Http\Request;

/*
 * What the redesigned console reads from the service: Explore facets, the purchase CTA, versions and the
 * checkout hand-off.
 */

function consoleListing(string $slug): array
{
    foreach (RegistryServiceFake::body('catalog-extensions')['data'] as $listing) {
        if ($listing['slug'] === $slug) {
            return $listing;
        }
    }

    throw new RuntimeException('No recorded listing ' . $slug);
}

function releasesPayload(): array
{
    return [
        'extension' => 'registry-demo-hello',
        'latest'    => '1.1.0',
        'channels'  => ['stable' => '1.1.0', 'beta' => '1.2.0-beta.1'],
        'releases'  => [
            ['id' => 'rel_3', 'version' => '1.2.0-beta.1', 'channel' => 'beta', 'status' => 'published', 'compat' => ['fleetbase' => '>=0.7'], 'size_bytes' => 2048, 'notes' => 'Beta', 'published_at' => '2026-10-20T00:00:00Z', 'yanked_at' => null],
            ['id' => 'rel_2', 'version' => '1.1.0', 'channel' => 'stable', 'status' => 'published', 'compat' => [], 'size_bytes' => 1024, 'notes' => null, 'published_at' => '2026-10-10T00:00:00Z', 'yanked_at' => null],
            ['id' => 'rel_1', 'version' => '1.0.0', 'channel' => 'stable', 'status' => 'yanked', 'compat' => [], 'size_bytes' => 900, 'notes' => null, 'published_at' => '2026-10-01T00:00:00Z', 'yanked_at' => '2026-10-02T00:00:00Z'],
            'not a release',
        ],
    ];
}

beforeEach(function () {
    $this->service = new RegistryServiceFake();
});

test('Explore passes pricing, sort and the hosting facet to the service and ignores unknown values', function () {
    $this->service
        ->on('GET', 'v1/catalog/extensions', 'catalog-extensions')
        ->on('GET', 'v1/instances/me', 'instances-me-installed')
        ->on('GET', 'v1/instances/me/updates', 'instances-me-updates');
    $client = RegistryClient::fromConfig(RegistryServiceFake::config());

    ServiceCatalog::query(Request::create('/registry-extensions', 'GET', ['explore' => 1, 'pricing' => 'paid', 'hosting' => 'both', 'sort' => 'newest']), $client, RegistryServiceFake::config());
    ServiceCatalog::query(Request::create('/registry-extensions', 'GET', ['explore' => 1, 'pricing' => 'gratis', 'hosting' => 'cloud', 'sort' => 'random']), $client, RegistryServiceFake::config());
    ServiceCatalog::query(Request::create('/registry-extensions', 'GET', ['explore' => 1, 'hosting' => 'moon']), $client, RegistryServiceFake::config());

    [$first, $second, $third] = $this->service->requestsTo('GET', 'v1/catalog/extensions');
    expect($first->url())->toContain('pricing=paid')
        ->and($first->url())->toContain('hosting=both')
        ->and($first->url())->toContain('sort=newest')
        ->and($second->url())->not->toContain('pricing=')
        ->and($second->url())->toContain('hosting=cloud')
        ->and($second->url())->not->toContain('sort=')
        ->and($third->url())->not->toContain('hosting=');
});

test('hiding installed extensions filters the whole catalog and pages the rest', function () {
    $this->service
        ->on('GET', 'v1/catalog/extensions', 'catalog-extensions')
        ->on('GET', 'v1/instances/me', 'instances-me-installed')
        ->on('GET', 'v1/instances/me/updates', 'instances-me-updates');
    $client = RegistryClient::fromConfig(RegistryServiceFake::config());

    $page = ServiceCatalog::query(Request::create('/registry-extensions', 'GET', ['explore' => 1, 'hide_installed' => 'true', 'limit' => 10]), $client, RegistryServiceFake::config());

    expect($page->total())->toBe(1)
        ->and($page->items()[0]['slug'])->toBe('registry-demo-pro')
        ->and($page->items()[0]['is_installed'])->toBeFalse();
});

test('install blocks carry a code the console branches on', function () {
    expect(ServiceCatalog::installBlock(false, 'both', 'self_hosted', false, false, ['a'], '1.0.0')['code'])->toBe('not_registered')
        ->and(ServiceCatalog::installBlock(true, 'cloud', 'self_hosted', false, false, ['a'], '1.0.0')['code'])->toBe('hosting')
        ->and(ServiceCatalog::installBlock(true, 'both', 'self_hosted', true, false, ['a'], '1.0.0')['code'])->toBe('licence_required')
        ->and(ServiceCatalog::installBlock(true, 'both', 'self_hosted', false, false, [], ''))->toMatchArray(['code' => 'no_release'])
        ->and(ServiceCatalog::installBlock(true, 'both', 'self_hosted', true, true, ['a'], '1.0.0'))->toBeNull();
});

test('a paid listing offers purchase only when the service checkout is available', function () {
    $listing             = consoleListing('registry-demo-pro');
    $without             = ServiceCatalog::buildState(RegistryServiceFake::body('instances-me'), [], [], true);
    $with                = ServiceCatalog::buildState(RegistryServiceFake::body('instances-me'), [], [], true, 'self_hosted', true);
    $without['licences'] = $with['licences'] = [];

    $blocked   = ServiceCatalog::transform($listing, $without);
    $available = ServiceCatalog::transform($listing, $with);

    expect($blocked['meta']['purchase_available'])->toBeFalse()
        ->and($blocked['meta']['install_blocked_code'])->toBe('licence_required')
        ->and($blocked['meta']['install_blocked_reason'])->toContain('not available yet')
        ->and($available['meta']['purchase_available'])->toBeTrue()
        ->and($available['meta']['install_blocked_reason'])->toBe('Purchase a licence for your organization to install this extension.')
        ->and($available['is_installable'])->toBeFalse();
});

test('checkout is on for a registered instance unless the service has none, and can be forced', function () {
    $auto = RegistryServiceFake::config();

    expect($auto->checkoutEnabled())->toBeTrue()
        ->and($auto->checkoutEnabled(false))->toBeFalse()
        ->and(RegistryServiceFake::config(['checkout' => 'false'])->checkoutEnabled())->toBeFalse()
        ->and(RegistryServiceFake::config(['checkout' => true])->checkoutEnabled(false))->toBeTrue()
        ->and(RegistryServiceFake::config(['instance_token' => null, 'checkout' => true])->checkoutEnabled())->toBeFalse()
        ->and((new RegistryConfig(['service_url' => '', 'checkout' => true]))->checkoutEnabled())->toBeFalse();
});

test('the publisher portal defaults to the service URL', function () {
    expect(RegistryServiceFake::config()->publisherUrl())->toBe(RegistryServiceFake::SERVICE_URL)
        ->and(RegistryServiceFake::config(['publisher_url' => 'publish.example.test/'])->publisherUrl())->toBe('https://publish.example.test')
        ->and((new RegistryConfig(['service_url' => '']))->publisherUrl())->toBeNull();
});

test('releases map with latest, installed and yanked flags', function () {
    $mapped = ServiceCatalog::transformReleases(releasesPayload(), '1.0.0');

    expect($mapped['latest'])->toBe('1.1.0')
        ->and($mapped['channels'])->toBe(['stable' => '1.1.0', 'beta' => '1.2.0-beta.1'])
        ->and($mapped['releases'])->toHaveCount(3)
        ->and($mapped['releases'][0])->toMatchArray(['version' => '1.2.0-beta.1', 'channel' => 'beta', 'is_latest' => false, 'size_bytes' => 2048])
        ->and($mapped['releases'][1])->toMatchArray(['version' => '1.1.0', 'is_latest' => true, 'is_installed' => false, 'is_yanked' => false])
        ->and($mapped['releases'][2])->toMatchArray(['version' => '1.0.0', 'is_installed' => true, 'is_yanked' => true]);
});

test('releases are read from the catalog releases endpoint and a missing listing is null', function () {
    $this->service
        ->respondWith('GET', 'v1/catalog/extensions/registry-demo-hello/releases', 200, releasesPayload())
        ->on('GET', 'v1/catalog/extensions/nope/releases', 'catalog-extension-not-found');
    $client = RegistryClient::fromConfig(RegistryServiceFake::config());

    expect(ServiceCatalog::releases('registry-demo-hello', $client)['releases'])->toHaveCount(3)
        ->and(ServiceCatalog::releases('nope', $client))->toBeNull();
});

test('a hosting mismatch is listed with its reason instead of being hidden', function () {
    $listing            = consoleListing('registry-demo-hello');
    $listing['hosting'] = 'cloud';
    $mapped             = ServiceCatalog::transform($listing, ServiceCatalog::buildState([], [], [], true));

    expect($mapped['is_installable'])->toBeFalse()
        ->and($mapped['meta']['hosting'])->toBe('cloud')
        ->and($mapped['meta']['install_blocked_code'])->toBe('hosting')
        ->and($mapped['meta']['install_blocked_reason'])->toBe('This extension is only available on Fleetbase Cloud.');
});

test('sidebar counts come from the instance state and local installs', function () {
    $state = ServiceCatalog::buildState(RegistryServiceFake::body('instances-me-installed'), RegistryServiceFake::body('instances-me-updates'), [], true);

    $counts = ServiceCatalog::counts($state, ['ext_local_only']);

    expect($counts['installed'])->toBe(count($state['installed']) + 1)
        ->and($counts['updates'])->toBe(count(array_filter($state['installed'], fn ($entry) => $entry['update_available'] ?? false)))
        ->and($counts['purchased'])->toBe(1);
});

test('a receipt on the licence wins over the one kept from the checkout', function () {
    $licence = ['id' => 'lic_1', 'kind' => 'paid', 'status' => 'active'];
    $stored  = ['receipt_url' => 'https://pay.stripe.test/r/kept', 'purchase' => 'pur_1', 'amount_total' => 4900, 'currency' => 'USD'];

    expect(ServiceCatalog::withReceipt($licence, $stored)['receipt_url'])->toBe('https://pay.stripe.test/r/kept')
        ->and(ServiceCatalog::withReceipt($licence, $stored)['purchase'])->toMatchArray(['id' => 'pur_1', 'amount_total' => 4900])
        ->and(ServiceCatalog::withReceipt([...$licence, 'receipt_url' => 'https://pay.stripe.test/r/service'], $stored)['receipt_url'])->toBe('https://pay.stripe.test/r/service')
        ->and(ServiceCatalog::withReceipt([...$licence, 'purchase' => ['receipt_url' => 'https://pay.stripe.test/r/nested']], $stored)['receipt_url'])->toBe('https://pay.stripe.test/r/nested')
        ->and(ServiceCatalog::withReceipt($licence)['receipt_url'])->toBeNull();
});
