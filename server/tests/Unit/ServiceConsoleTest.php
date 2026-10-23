<?php

use Fleetbase\RegistryBridge\Exceptions\RegistryServiceException;
use Fleetbase\RegistryBridge\Support\RegistryClient;
use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Fleetbase\RegistryBridge\Support\ServiceCatalog;
use Fleetbase\RegistryBridge\Support\ServiceCheckout;
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

test('Explore passes pricing, sort and the both-hosting facet to the service and ignores unknown values', function () {
    $this->service
        ->on('GET', 'v1/catalog/extensions', 'catalog-extensions')
        ->on('GET', 'v1/instances/me', 'instances-me-installed')
        ->on('GET', 'v1/instances/me/updates', 'instances-me-updates');
    $client = RegistryClient::fromConfig(RegistryServiceFake::config());

    ServiceCatalog::query(Request::create('/registry-extensions', 'GET', ['explore' => 1, 'pricing' => 'paid', 'hosting' => 'both', 'sort' => 'newest']), $client, RegistryServiceFake::config());
    ServiceCatalog::query(Request::create('/registry-extensions', 'GET', ['explore' => 1, 'pricing' => 'gratis', 'hosting' => 'cloud', 'sort' => 'random']), $client, RegistryServiceFake::config());

    [$first, $second] = $this->service->requestsTo('GET', 'v1/catalog/extensions');
    expect($first->url())->toContain('pricing=paid')
        ->and($first->url())->toContain('hosting=both')
        ->and($first->url())->toContain('sort=newest')
        ->and($second->url())->not->toContain('pricing=')
        ->and($second->url())->toContain('hosting=self_hosted')
        ->and($second->url())->not->toContain('sort=');
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

test('checkout follows the service feature flag in auto mode and can be forced', function () {
    $auto = RegistryServiceFake::config();

    expect($auto->checkoutEnabled([]))->toBeFalse()
        ->and($auto->checkoutEnabled(['checkout' => true]))->toBeTrue()
        ->and(RegistryServiceFake::config(['checkout' => 'false'])->checkoutEnabled(['checkout' => true]))->toBeFalse()
        ->and(RegistryServiceFake::config(['checkout' => true])->checkoutEnabled([]))->toBeTrue()
        ->and(RegistryServiceFake::config(['instance_token' => null, 'checkout' => true])->checkoutEnabled(['checkout' => true]))->toBeFalse()
        ->and((new RegistryConfig(['checkout' => true]))->checkoutEnabled(['checkout' => true]))->toBeFalse();
});

test('the publisher portal defaults to the service URL', function () {
    expect(RegistryServiceFake::config()->publisherUrl())->toBe(RegistryServiceFake::SERVICE_URL)
        ->and(RegistryServiceFake::config(['publisher_url' => 'publish.example.test/'])->publisherUrl())->toBe('https://publish.example.test')
        ->and((new RegistryConfig([]))->publisherUrl())->toBeNull();
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

test('starting a checkout is refused while the service does not offer it', function () {
    $this->service->on('GET', 'v1/instances/me', 'instances-me');
    $config = RegistryServiceFake::config();

    expect(fn () => ServiceCheckout::start(RegistryClient::fromConfig($config), $config, 'ext_gzh4wgc9nr', 'https://fleet.example.test/extensions'))
        ->toThrow(RegistryServiceException::class, 'not available yet');
    expect($this->service->requestsTo('POST', 'v1/instances/me/purchases/checkout'))->toBeEmpty();
});

test('a checkout posts the extension and return URL and returns the hosted URL and hand-off', function () {
    $me             = RegistryServiceFake::body('instances-me');
    $me['features'] = ['checkout' => true];
    $this->service
        ->respondWith('GET', 'v1/instances/me', 200, $me)
        ->respondWith('POST', 'v1/instances/me/purchases/checkout', 201, ['url' => 'https://checkout.stripe.test/c/cs_1', 'handoff' => 'hof_1']);
    $config = RegistryServiceFake::config();

    $checkout = ServiceCheckout::start(RegistryClient::fromConfig($config), $config, 'ext_gzh4wgc9nr', 'https://fleet.example.test/extensions?extension_id=ext_gzh4wgc9nr', 'buyer@example.test');

    $body = $this->service->requestsTo('POST', 'v1/instances/me/purchases/checkout')[0]->data();
    expect($checkout)->toBe(['url' => 'https://checkout.stripe.test/c/cs_1', 'handoff' => 'hof_1'])
        ->and($body['extension'])->toBe('ext_gzh4wgc9nr')
        ->and($body['return_url'])->toBe('https://fleet.example.test/extensions?extension_id=ext_gzh4wgc9nr')
        ->and($body['buyer_email'])->toBe('buyer@example.test');
});

test('a forced checkout against a service without the endpoint reports it as not available', function () {
    $this->service
        ->on('GET', 'v1/instances/me', 'instances-me')
        ->respondWith('POST', 'v1/instances/me/purchases/checkout', 404, ['errors' => ['Not found.']]);
    $config = RegistryServiceFake::config(['checkout' => true]);

    try {
        ServiceCheckout::start(RegistryClient::fromConfig($config), $config, 'ext_gzh4wgc9nr', 'https://fleet.example.test/extensions');
        $this->fail('expected a RegistryServiceException');
    } catch (RegistryServiceException $e) {
        expect($e->status())->toBe(501)
            ->and($e->getMessage())->toBe(ServiceCheckout::unavailableReason());
    }
});

test('hand-off statuses map onto pending, completed and failed', function () {
    expect(ServiceCheckout::describe(['status' => 'open'])['status'])->toBe('pending')
        ->and(ServiceCheckout::describe([])['status'])->toBe('pending')
        ->and(ServiceCheckout::describe(['status' => 'PAID', 'receipt_url' => 'https://pay.stripe.test/r/1'])['receipt_url'])->toBe('https://pay.stripe.test/r/1')
        ->and(ServiceCheckout::describe(['purchase' => ['status' => 'completed', 'extension' => ['id' => 'ext_1']]]))->toMatchArray(['status' => 'completed', 'extension' => ['id' => 'ext_1']])
        ->and(ServiceCheckout::describe(['status' => 'expired', 'message' => 'Session expired'])['status'])->toBe('failed');
});

test('a hand-off is polled uncached and forgets the cached instance state', function () {
    $this->service
        ->respondWith('GET', 'v1/instances/me/purchases/hof_1', 200, ['status' => 'pending'])
        ->thenRespondWith('GET', 'v1/instances/me/purchases/hof_1', 200, ['status' => 'completed']);
    $client = RegistryClient::fromConfig(RegistryServiceFake::config());

    expect($client->purchase('hof_1')['status'])->toBe('pending')
        ->and($client->purchase('hof_1')['status'])->toBe('completed')
        ->and($this->service->requestsTo('GET', 'v1/instances/me/purchases/hof_1'))->toHaveCount(2);
});
