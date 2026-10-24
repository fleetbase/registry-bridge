<?php

namespace Fleetbase\RegistryBridge\Support;

use Fleetbase\RegistryBridge\Exceptions\RegistryException;
use Fleetbase\RegistryBridge\Exceptions\RegistryServiceException;
use Illuminate\Support\Facades\Cache;

/**
 * Purchases through the Registry Service checkout ("Instance checkout" in the service README).
 *
 * The console starts a hosted Stripe Checkout (`POST /v1/instances/me/purchases/checkout`), redirects the
 * buyer to its `url`, and when Stripe sends the buyer back with `handoff=<id>` polls
 * `GET /v1/instances/me/purchases/{handoff}` until the webhook has created the purchase and licence. The
 * hand-off is `open`, then `complete` (with `purchase`, its `receipt_url` and `licence`) or `expired`.
 */
class ServiceCheckout
{
    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    /** How long a service without the checkout endpoint is remembered, in seconds. */
    public const UNSUPPORTED_TTL = 3600;

    /** Hand-off statuses mapped onto the three the console shows. */
    protected const STATUS_MAP = [
        'open'     => self::PENDING,
        'complete' => self::COMPLETED,
        'expired'  => self::FAILED,
    ];

    /**
     * Whether paid listings can be bought here: a registered instance whose service has not answered that it
     * has no checkout (`REGISTRY_SERVICE_CHECKOUT` forces it either way).
     */
    public static function available(RegistryConfig $config): bool
    {
        return $config->checkoutEnabled(!Cache::has(static::unsupportedKey($config)));
    }

    /**
     * Start a checkout for one listing and return where to send the buyer.
     *
     * @return array{url: string, handoff: string, status: string, amount: int|null, currency: string|null}
     *
     * @throws RegistryException when checkout is off, the service lacks the endpoint, or it refuses
     */
    public static function start(RegistryClient $client, RegistryConfig $config, string $extension, string $returnUrl, ?string $cancelUrl = null, ?string $email = null): array
    {
        if (!$config->isRegistered()) {
            throw new RegistryServiceException('Register this instance with the registry service before purchasing extensions.', 409);
        }

        if (!static::available($config)) {
            throw new RegistryServiceException(static::unavailableReason(), 409);
        }

        try {
            $checkout = $client->createCheckout([
                'extension'  => $extension,
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'email'      => $email,
            ]);
        } catch (RegistryServiceException $e) {
            if (in_array($e->status(), [404, 405], true) && static::isMissingEndpoint($e)) {
                Cache::put(static::unsupportedKey($config), true, static::UNSUPPORTED_TTL);

                throw new RegistryServiceException(static::unavailableReason(), 501, [], [], $e);
            }

            throw $e;
        }

        return $checkout;
    }

    /**
     * The hand-off as the console reads it: `status` (pending, completed or failed), the service's own status,
     * the extension, the purchase and its receipt.
     *
     * @param array<string, mixed> $body `GET /v1/instances/me/purchases/{handoff}` body
     *
     * @return array{status: string, service_status: string|null, handoff: string|null, extension: array<string, mixed>|null, purchase: array<string, mixed>|null, receipt_url: string|null, amount: int|null, currency: string|null, expires_at: string|null}
     */
    public static function describe(array $body): array
    {
        $serviceStatus = is_string($body['status'] ?? null) ? strtolower($body['status']) : null;
        $purchase      = is_array($body['purchase'] ?? null) ? $body['purchase'] : null;
        $receipt       = $purchase['receipt_url'] ?? null;

        return [
            'status'         => static::STATUS_MAP[$serviceStatus] ?? static::PENDING,
            'service_status' => $serviceStatus,
            'handoff'        => is_string($body['handoff'] ?? null) ? $body['handoff'] : null,
            'extension'      => is_array($body['extension'] ?? null) ? $body['extension'] : null,
            'purchase'       => $purchase,
            'receipt_url'    => is_string($receipt) ? $receipt : null,
            'amount'         => is_numeric($body['amount'] ?? null) ? (int) $body['amount'] : null,
            'currency'       => is_string($body['currency'] ?? null) ? $body['currency'] : null,
            'expires_at'     => is_string($body['expires_at'] ?? null) ? $body['expires_at'] : null,
        ];
    }

    public static function unavailableReason(): string
    {
        return 'Purchasing through the registry service is not available yet.';
    }

    /**
     * A 404 for an unknown extension carries the service's message; a missing route does not.
     */
    protected static function isMissingEndpoint(RegistryServiceException $e): bool
    {
        return $e->status() === 405 || !str_contains(strtolower($e->getMessage()), 'extension');
    }

    protected static function unsupportedKey(RegistryConfig $config): string
    {
        return 'registry-bridge:checkout-unsupported:' . sha1((string) $config->serviceUrl());
    }
}
