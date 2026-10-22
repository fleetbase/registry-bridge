<?php

namespace Fleetbase\RegistryBridge\Tests\Support;

use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Facade;
use Psr\Log\NullLogger;

/**
 * Offline Registry Service for support-class tests.
 *
 * Boots just enough of the framework behind the facades the support classes use (an array cache, the HTTP
 * client factory and a null logger) and answers requests with responses recorded from a running service
 * (`server/tests/Fixtures/registry-service`, see the README there).
 */
class RegistryServiceFake
{
    public const SERVICE_URL = 'https://registry.example.test';

    public const INSTANCE_TOKEN = 'flbi_buyer00000000000000000000000000000000000';

    public Factory $http;

    public Repository $cache;

    /** @var array<int, Request> */
    public array $requests = [];

    /** @var array<string, array{status: int, body: mixed}|array<int, array{status: int, body: mixed}>> */
    protected array $routes = [];

    public function __construct()
    {
        $this->cache = new Repository(new ArrayStore());
        $this->http  = new Factory();

        $container = new Container();
        $container->instance('cache', $this->cache);
        $container->instance(Factory::class, $this->http);
        $container->instance('log', new NullLogger());

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        $this->http->fake(function (Request $request) {
            $this->requests[] = $request;

            return $this->respond($request);
        });
    }

    /**
     * A recorded response: `{request: {method, path}, status, body}`.
     *
     * @return array{request: array{method: string, path: string}, status: int, body: mixed}
     */
    public static function fixture(string $name): array
    {
        $file = dirname(__DIR__) . '/Fixtures/registry-service/' . $name . '.json';

        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The body of a recorded response.
     */
    public static function body(string $name): mixed
    {
        return static::fixture($name)['body'];
    }

    /**
     * Settings for a registered v2 instance, overridable per test.
     *
     * @param array<string, mixed> $overrides
     */
    public static function config(array $overrides = []): RegistryConfig
    {
        return new RegistryConfig(array_merge([
            'host'           => 'https://registry.fleetbase.io',
            'service_url'    => static::SERVICE_URL,
            'instance_token' => static::INSTANCE_TOKEN,
            'instance_kind'  => 'self_hosted',
        ], $overrides));
    }

    /**
     * Answer `METHOD path` (path without query string) with a recorded fixture, or a list of them in turn.
     */
    public function on(string $method, string $path, string ...$fixtures): static
    {
        $responses = array_map(function (string $name) {
            $fixture = static::fixture($name);

            return ['status' => $fixture['status'], 'body' => $fixture['body']];
        }, $fixtures);

        $this->routes[strtoupper($method) . ' ' . ltrim($path, '/')] = $responses;

        return $this;
    }

    /**
     * Answer `METHOD path` with an inline response.
     */
    public function respondWith(string $method, string $path, int $status, mixed $body = null): static
    {
        $this->routes[strtoupper($method) . ' ' . ltrim($path, '/')] = [['status' => $status, 'body' => $body]];

        return $this;
    }

    /**
     * Append an inline response to the sequence for `METHOD path`.
     */
    public function thenRespondWith(string $method, string $path, int $status, mixed $body = null): static
    {
        $this->routes[strtoupper($method) . ' ' . ltrim($path, '/')][] = ['status' => $status, 'body' => $body];

        return $this;
    }

    /**
     * Requests whose path (without query string) matches.
     *
     * @return array<int, Request>
     */
    public function requestsTo(string $method, string $path): array
    {
        return array_values(array_filter($this->requests, fn (Request $request) => $request->method() === strtoupper($method) && static::path($request) === ltrim($path, '/')));
    }

    protected function respond(Request $request): mixed
    {
        $key = $request->method() . ' ' . static::path($request);
        if (!isset($this->routes[$key]) || $this->routes[$key] === []) {
            return Factory::response(['errors' => ['No recorded response for ' . $key]], 599);
        }

        // Replay a sequence, repeating the last response once it is exhausted
        $response = count($this->routes[$key]) > 1 ? array_shift($this->routes[$key]) : $this->routes[$key][0];

        return Factory::response($response['body'], $response['status']);
    }

    protected static function path(Request $request): string
    {
        return ltrim(rawurldecode((string) parse_url($request->url(), PHP_URL_PATH)), '/');
    }
}
