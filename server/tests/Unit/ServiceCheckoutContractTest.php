<?php

use Fleetbase\RegistryBridge\Exceptions\RegistryServiceException;
use Fleetbase\RegistryBridge\Support\RegistryClient;
use Fleetbase\RegistryBridge\Support\ServiceCatalog;
use Fleetbase\RegistryBridge\Support\ServiceCheckout;
use Fleetbase\RegistryBridge\Support\ServiceReceipts;
use Fleetbase\RegistryBridge\Tests\Support\RegistryServiceFake;
use Illuminate\Http\Request;

/*
 * The instance checkout contract ("Instance checkout" in the Registry Service README), against responses
 * recorded from the Phase 4 service (`record_phase4.py`).
 */

const CHECKOUT_PATH = 'v1/instances/me/purchases/checkout';

function checkoutFixtureStatus(string $name): array
{
    $fixture = RegistryServiceFake::fixture($name);

    return [$fixture['status'], $fixture['body']];
}

beforeEach(function () {
    $this->service = new RegistryServiceFake();
    $this->config  = RegistryServiceFake::config();
    $this->client  = RegistryClient::fromConfig($this->config);
});

test('a started checkout returns the hosted URL and hand-off, sending the contract fields', function () {
    $open = RegistryServiceFake::body('purchases-handoff-open');
    $this->service->respondWith('POST', CHECKOUT_PATH, 201, $open);

    $checkout = ServiceCheckout::start($this->client, $this->config, 'acme-route-planner', 'https://fleet.example.test/extensions?purchase_return=1', null, 'ops@example.test');

    $body = $this->service->requestsTo('POST', CHECKOUT_PATH)[0]->data();
    expect($checkout)->toMatchArray(['url' => $open['url'], 'handoff' => $open['handoff'], 'status' => 'open', 'amount' => 2900, 'currency' => 'USD'])
        ->and($body)->toBe(['extension' => 'acme-route-planner', 'return_url' => 'https://fleet.example.test/extensions?purchase_return=1', 'email' => 'ops@example.test']);
});

test('the service refusals pass through with their status and message', function (string $fixture, int $status, string $message) {
    $this->service->on('POST', CHECKOUT_PATH, $fixture);

    try {
        ServiceCheckout::start($this->client, $this->config, 'acme-route-planner', 'https://fleet.example.test/extensions');
        $this->fail('expected a refusal');
    } catch (RegistryServiceException $e) {
        expect($e->status())->toBe($status)
            ->and($e->getMessage())->toContain($message)
            ->and(ServiceCheckout::available($this->config))->toBeTrue('a refusal does not turn checkout off');
    }
})->with([
    'already licensed'        => ['checkout-already-licensed', 409, 'already holds a licence'],
    'publisher not onboarded' => ['checkout-no-stripe', 422, 'has not finished setting up payouts'],
    'hosting mismatch'        => ['checkout-hosting-mismatch', 422, 'only available for cloud instances'],
    'unknown extension'       => ['checkout-unknown-extension', 404, 'Extension not found'],
    'validation'              => ['checkout-validation', 422, 'return url field is required'],
]);

test('a service without the checkout route turns checkout off for a while', function () {
    $this->service->respondWith('POST', CHECKOUT_PATH, 404, ['errors' => ['Resource not found.']]);

    expect(fn () => ServiceCheckout::start($this->client, $this->config, 'acme-route-planner', 'https://fleet.example.test/extensions'))
        ->toThrow(RegistryServiceException::class, ServiceCheckout::unavailableReason());
    expect(ServiceCheckout::available($this->config))->toBeFalse()
        ->and(ServiceCheckout::available(RegistryServiceFake::config(['checkout' => true])))->toBeTrue('forcing it on still works');
});

test('recorded hand-offs map onto pending, completed and failed', function () {
    $open     = ServiceCheckout::describe(RegistryServiceFake::body('purchases-handoff-open'));
    $expired  = ServiceCheckout::describe(RegistryServiceFake::body('purchases-handoff-expired'));
    $complete = ServiceCheckout::describe(RegistryServiceFake::body('purchases-handoff-complete'));

    expect($open)->toMatchArray(['status' => 'pending', 'service_status' => 'open', 'receipt_url' => null])
        ->and($expired)->toMatchArray(['status' => 'failed', 'service_status' => 'expired'])
        ->and($complete)->toMatchArray(['status' => 'completed', 'service_status' => 'complete', 'receipt_url' => 'https://pay.stripe.com/receipts/payment/bridge_test'])
        ->and($complete['extension']['slug'])->toBe('acme-route-planner')
        ->and($complete['purchase']['licence']['max_activations'])->toBe(5);
});

test('a completed hand-off keeps its receipt for Purchased', function () {
    $this->service
        ->on('GET', 'v1/instances/me/purchases/chk_3tampcinhk', 'purchases-handoff-complete')
        ->on('GET', 'v1/instances/me', 'instances-me-purchased')
        ->on('GET', 'v1/instances/me/updates', 'instances-me-updates')
        ->on('GET', 'v1/catalog/extensions', 'catalog-extensions-every-hosting');

    ServiceReceipts::remember($this->config, ServiceCheckout::describe($this->client->purchase('chk_3tampcinhk')));
    $purchased = ServiceCatalog::purchased($this->client, $this->config);
    $route     = collect($purchased)->firstWhere('slug', 'acme-route-planner');

    expect($route['meta']['licence']['receipt_url'])->toBe('https://pay.stripe.com/receipts/payment/bridge_test')
        ->and($route['meta']['licence']['purchase'])->toMatchArray(['id' => 'pur_rqcusnwvoh', 'amount_total' => 3190, 'currency' => 'USD'])
        ->and($route['meta']['licence']['max_activations'])->toBe(5)
        ->and(collect($purchased)->firstWhere('slug', 'registry-demo-pro')['meta']['licence']['receipt_url'])->toBeNull();
});

test('a hand-off that is not complete keeps no receipt', function () {
    ServiceReceipts::remember($this->config, ServiceCheckout::describe(RegistryServiceFake::body('purchases-handoff-open')));

    expect(ServiceReceipts::all($this->config))->toBe([]);
});

test('Explore lists every hosting and explains the ones this instance cannot install', function () {
    $this->service
        ->on('GET', 'v1/catalog/extensions', 'catalog-extensions-every-hosting')
        ->on('GET', 'v1/instances/me', 'instances-me-purchased')
        ->on('GET', 'v1/instances/me/updates', 'instances-me-updates');

    $items = collect(ServiceCatalog::query(Request::create('/', 'GET', ['explore' => 1]), $this->client, $this->config)->items())->keyBy('slug');

    expect($items->keys()->sort()->values()->all())->toBe(['acme-fleet-insights', 'acme-route-planner', 'registry-demo-hello', 'registry-demo-pro'])
        ->and($items['acme-fleet-insights']['meta']['install_blocked_code'])->toBe('hosting')
        ->and($items['acme-fleet-insights']['meta']['install_blocked_reason'])->toBe('This extension is only available on Fleetbase Cloud.')
        ->and($items['acme-fleet-insights']['meta']['purchase_available'])->toBeFalse()
        ->and($items['registry-demo-pro']['is_purchased'])->toBeTrue();
});

test('recorded releases map for the Versions tab', function () {
    $this->service
        ->on('GET', 'v1/catalog/extensions/registry-demo-hello/releases', 'catalog-releases')
        ->on('GET', 'v1/catalog/extensions/does-not-exist/releases', 'catalog-releases-not-found');

    $releases = ServiceCatalog::releases('registry-demo-hello', $this->client, '1.0.0');

    expect($releases['latest'])->toBe('1.0.0')
        ->and($releases['releases'][0])->toMatchArray(['version' => '1.0.0', 'is_latest' => true, 'is_installed' => true, 'channel' => 'stable'])
        ->and(ServiceCatalog::releases('does-not-exist', $this->client))->toBeNull();
});
