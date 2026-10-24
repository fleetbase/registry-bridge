<?php

use Fleetbase\RegistryBridge\Exceptions\RegistryServiceException;
use Fleetbase\RegistryBridge\Exceptions\RegistryUnavailableException;
use Fleetbase\RegistryBridge\Support\RegistryClient;
use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Fleetbase\RegistryBridge\Tests\Support\RegistryServiceFake;

beforeEach(function () {
    $this->service = new RegistryServiceFake();
});

test('the service URL is normalised and decides whether the service is used', function () {
    expect((new RegistryConfig(['service_url' => 'registry.example.test/']))->serviceUrl())->toBe('https://registry.example.test')
        ->and((new RegistryConfig(['service_url' => 'registry.example.test']))->usesService())->toBeTrue()
        ->and((new RegistryConfig([]))->usesService())->toBeFalse()
        ->and((new RegistryConfig(['instance_kind' => 'cloud']))->instanceKind())->toBe('cloud')
        ->and((new RegistryConfig(['instance_kind' => 'other']))->instanceKind())->toBe('self_hosted')
        ->and((new RegistryConfig([]))->instanceKind())->toBe('self_hosted');
});

test('a client targets the service URL with the instance token, or the default service', function () {
    $client  = RegistryClient::fromConfig(RegistryServiceFake::config());
    $default = RegistryClient::fromConfig(new RegistryConfig([]));

    expect($client->host())->toBe(RegistryServiceFake::SERVICE_URL)
        ->and($client->hasToken())->toBeTrue()
        ->and($default->host())->toBe(RegistryConfig::DEFAULT_SERVICE_URL)
        ->and($default->hasToken())->toBeFalse();
});

test('catalog pages come from /v1/catalog/extensions and are cached', function () {
    $this->service->on('GET', 'v1/catalog/extensions', 'catalog-extensions');
    $client = RegistryClient::fromConfig(RegistryServiceFake::config());

    $page = $client->catalogExtensions(['q' => 'demo', 'page' => 1, 'limit' => 30, 'category' => '']);
    $client->catalogExtensions(['q' => 'demo', 'page' => 1, 'limit' => 30]);

    $requests = $this->service->requestsTo('GET', 'v1/catalog/extensions');
    expect($page['data'])->toHaveCount(2)
        ->and($page['data'][0]['slug'])->toBe('registry-demo-hello')
        ->and($page['meta']['total'])->toBe(2)
        ->and($requests)->toHaveCount(1)
        ->and($requests[0]->url())->toStartWith(RegistryServiceFake::SERVICE_URL . '/v1/catalog/extensions?')
        ->and($requests[0]->url())->toContain('q=demo')
        ->and($requests[0]->url())->not->toContain('category=');
});

test('the whole catalog is read page by page', function () {
    $first                      = RegistryServiceFake::body('catalog-extensions');
    $first['data']              = [$first['data'][0]];
    $first['meta']['last_page'] = 2;
    $second                     = RegistryServiceFake::body('catalog-extensions');
    $second['data']             = [$second['data'][1]];

    $this->service->respondWith('GET', 'v1/catalog/extensions', 200, $first);
    $client     = RegistryClient::fromConfig(RegistryServiceFake::config());
    $client->catalogExtensions(['page' => 1, 'limit' => RegistryClient::CATALOG_PAGE_SIZE]);
    $this->service->respondWith('GET', 'v1/catalog/extensions', 200, $second);

    $extensions = $client->allCatalogExtensions();

    expect(array_column($extensions, 'slug'))->toBe(['registry-demo-hello', 'registry-demo-pro']);
});

test('a listing is read by slug and a missing one is null without opening the circuit', function () {
    $this->service
        ->on('GET', 'v1/catalog/extensions/registry-demo-pro', 'catalog-extension')
        ->on('GET', 'v1/catalog/extensions/does-not-exist', 'catalog-extension-not-found');
    $client = RegistryClient::fromConfig(RegistryServiceFake::config());

    $listing = $client->catalogExtension('registry-demo-pro');
    foreach (range(1, RegistryClient::CIRCUIT_FAILURE_THRESHOLD + 1) as $attempt) {
        expect($client->catalogExtension('does-not-exist'))->toBeNull();
    }

    expect($listing['packages']['composer'])->toBe('registry-demo/pro-api')
        ->and($listing['description'])->toBe('Demo listing used to exercise the npm and Composer endpoints.')
        ->and(RegistryClient::isCircuitOpen())->toBeFalse();
});

test('categories come from /v1/catalog/categories', function () {
    $this->service->on('GET', 'v1/catalog/categories', 'catalog-categories');

    $categories = RegistryClient::fromConfig(RegistryServiceFake::config())->catalogCategories();

    expect($categories)->not->toBeEmpty()
        ->and($categories[0]['slug'])->toBe('telematics')
        ->and($categories[0]['extensions_count'])->toBe(2);
});

test('instance state is read with the instance token', function () {
    $this->service
        ->on('GET', 'v1/instances/me', 'instances-me-installed')
        ->on('GET', 'v1/instances/me/updates', 'instances-me-updates');
    $client = RegistryClient::fromConfig(RegistryServiceFake::config());

    $me      = $client->instance();
    $updates = $client->updates();

    $request = $this->service->requestsTo('GET', 'v1/instances/me')[0];
    expect($request->header('Authorization'))->toBe(['Bearer ' . RegistryServiceFake::INSTANCE_TOKEN])
        ->and($me['instance']['name'])->toBe('Acme Fleet (self-hosted)')
        ->and($me['licences'])->toHaveCount(2)
        ->and($updates['data'][0]['update_available'])->toBeTrue()
        ->and($updates['meta']['updates_available'])->toBe(1);
});

test('instance calls without a registration fail before reaching the service', function () {
    $client = RegistryClient::fromConfig(RegistryServiceFake::config(['instance_token' => null]));

    expect(fn () => $client->instance())->toThrow(RegistryServiceException::class, 'not registered');
    expect($this->service->requests)->toBeEmpty();
});

test('registration exchanges the code for an instance token without sending a bearer token', function () {
    $this->service->on('POST', 'v1/instances/register', 'instances-register');
    $client = RegistryClient::fromConfig(RegistryServiceFake::config());

    $registration = $client->registerInstance(['code' => 'W9QA-SC8R-37JT-9PA8', 'name' => 'Acme Fleet (self-hosted)', 'kind' => 'self_hosted']);

    $request = $this->service->requestsTo('POST', 'v1/instances/register')[0];
    expect($registration['token'])->toBe('flbi_buyer00000000000000000000000000000000000')
        ->and($registration['rotated'])->toBeFalse()
        ->and($registration['instance']['organization']['slug'])->toBe('registry-demo-buyer')
        ->and($request->hasHeader('Authorization'))->toBeFalse()
        ->and($request->data())->toBe(['code' => 'W9QA-SC8R-37JT-9PA8', 'name' => 'Acme Fleet (self-hosted)', 'kind' => 'self_hosted']);
});

test('registering again sends the previous token and reports the rotated instance', function () {
    $this->service->on('POST', 'v1/instances/register', 'instances-register-rotated');
    $client = RegistryClient::fromConfig(RegistryServiceFake::config());

    $registration = $client->registerInstance(['code' => 'W9QA-SC8R-37JT-9PA8', 'name' => 'Acme Fleet (self-hosted)', 'kind' => 'self_hosted', 'previous_token' => RegistryServiceFake::INSTANCE_TOKEN]);

    $request = $this->service->requestsTo('POST', 'v1/instances/register')[0];
    expect($registration['rotated'])->toBeTrue()
        ->and($registration['instance']['id'])->toBe('inst_rsmdtgevid')
        ->and($registration['token'])->not->toBe(RegistryServiceFake::INSTANCE_TOKEN)
        ->and($request->data()['previous_token'])->toBe(RegistryServiceFake::INSTANCE_TOKEN)
        ->and($request->hasHeader('Authorization'))->toBeFalse();
});

test('a refused registration surfaces the service error', function () {
    $this->service->on('POST', 'v1/instances/register', 'instances-register-invalid-code');
    $client = RegistryClient::fromConfig(RegistryServiceFake::config(['instance_token' => null]));

    try {
        $client->registerInstance(['code' => 'AAAA-BBBB-CCCC-DDDD', 'name' => 'Bad', 'kind' => 'self_hosted']);
        $this->fail('Expected a RegistryServiceException');
    } catch (RegistryServiceException $e) {
        expect($e->status())->toBe(401)
            ->and($e->getMessage())->toBe('The registration code is invalid, expired or already used.');
    }
});

test('validation failures keep the field errors', function () {
    $this->service->on('POST', 'v1/instances/me/installs', 'instances-installs-validation');
    $client = RegistryClient::fromConfig(RegistryServiceFake::config());

    try {
        $client->reportInstall(['extension' => 'registry-demo-hello']);
        $this->fail('Expected a RegistryServiceException');
    } catch (RegistryServiceException $e) {
        expect($e->status())->toBe(422)
            ->and($e->fields())->toHaveKeys(['version', 'release', 'status']);
    }
});

test('install reports post to the instance API and refresh instance state', function () {
    $this->service
        ->on('GET', 'v1/instances/me/updates', 'instances-me-updates-current', 'instances-me-updates')
        ->on('POST', 'v1/instances/me/installs', 'instances-installs-installed');
    $client = RegistryClient::fromConfig(RegistryServiceFake::config());

    expect($client->updates()['data'][0]['update_available'])->toBeFalse();
    $result = $client->reportInstall(['extension' => 'registry-demo-hello', 'version' => '1.0.0', 'status' => 'installed', 'error' => null]);

    $request = $this->service->requestsTo('POST', 'v1/instances/me/installs')[0];
    expect($request->data())->toBe(['extension' => 'registry-demo-hello', 'version' => '1.0.0', 'status' => 'installed'])
        ->and($result['install']['status'])->toBe('installed')
        ->and($result['activation']['licence']['kind'])->toBe('free')
        ->and($client->updates()['data'][0]['update_available'])->toBeTrue();
});

test('a paid extension without a licence is refused with the service message', function () {
    $this->service->on('POST', 'v1/instances/me/installs', 'instances-installs-paid-forbidden');
    $client = RegistryClient::fromConfig(RegistryServiceFake::config());

    expect(fn () => $client->reportInstall(['extension' => 'registry-demo-pro', 'version' => '1.0.0', 'status' => 'installing']))
        ->toThrow(RegistryServiceException::class, "'registry-demo-pro' is a paid extension and your organization does not hold an active licence for it.");
});

test('uninstall reports keep the slash of scoped package names', function () {
    $this->service->on('DELETE', 'v1/instances/me/installs/@registry-demo/hello-engine', 'instances-installs-uninstall');
    $client = RegistryClient::fromConfig(RegistryServiceFake::config());

    $result = $client->reportUninstall('@registry-demo/hello-engine');

    expect($this->service->requestsTo('DELETE', 'v1/instances/me/installs/@registry-demo/hello-engine'))->toHaveCount(1)
        ->and($this->service->requests[0]->url())->toBe(RegistryServiceFake::SERVICE_URL . '/v1/instances/me/installs/%40registry-demo/hello-engine')
        ->and($result['deactivated'])->toBe(1)
        ->and($result['install']['status'])->toBe('uninstalled');
});

test('an outage serves the last good catalog', function () {
    $this->service->on('GET', 'v1/catalog/categories', 'catalog-categories');
    $client = new class(null, null, RegistryServiceFake::config()) extends RegistryClient {
        public function freshKey(string $path): string
        {
            return $this->cacheKey($path, []);
        }
    };
    $categories = $client->catalogCategories();

    // The fresh copy expires; the service is down
    $this->service->cache->forget($client->freshKey(RegistryClient::CATALOG_CATEGORIES_PATH));
    $this->service->respondWith('GET', 'v1/catalog/categories', 503, ['errors' => ['Service Unavailable']]);

    expect($client->catalogCategories())->toBe($categories)
        ->and($this->service->requestsTo('GET', 'v1/catalog/categories'))->toHaveCount(2);
});

test('repeated outages open the circuit and fail fast without a cached catalog', function () {
    $this->service->respondWith('GET', 'v1/catalog/categories', 503, ['errors' => ['Service Unavailable']]);
    $client = RegistryClient::fromConfig(RegistryServiceFake::config());

    foreach (range(1, RegistryClient::CIRCUIT_FAILURE_THRESHOLD) as $attempt) {
        expect(fn () => $client->catalogCategories())->toThrow(RegistryUnavailableException::class);
    }

    $before = count($this->service->requests);
    expect(fn () => $client->catalogCategories())->toThrow(RegistryUnavailableException::class, 'circuit is open')
        ->and(RegistryClient::isCircuitOpen())->toBeTrue()
        ->and(count($this->service->requests))->toBe($before);
});

test('testing the connection counts the catalog and checks the instance token', function () {
    $this->service
        ->on('GET', 'v1/catalog/extensions', 'catalog-extensions-search')
        ->on('GET', 'v1/instances/me', 'instances-me');

    $result = RegistryClient::fromConfig(RegistryServiceFake::config())->testConnection();

    expect($result['ok'])->toBeTrue()
        ->and($result['api_version'])->toBe('v2')
        ->and($result['extension_count'])->toBe(1)
        ->and($result['instance']['ok'])->toBeTrue()
        ->and($result['instance']['name'])->toBe('Acme Fleet (self-hosted)')
        ->and($result['instance']['organization'])->toBe('Registry Demo Buyer');
});

test('testing the connection reports a rejected instance token', function () {
    $this->service
        ->on('GET', 'v1/catalog/extensions', 'catalog-extensions')
        ->on('GET', 'v1/instances/me', 'instances-me-unauthenticated');

    $result = RegistryClient::fromConfig(RegistryServiceFake::config())->testConnection();

    expect($result['ok'])->toBeTrue()
        ->and($result['instance']['ok'])->toBeFalse()
        ->and($result['instance']['status'])->toBe(401);
});
