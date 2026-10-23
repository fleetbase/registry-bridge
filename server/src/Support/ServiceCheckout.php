<?php

namespace Fleetbase\RegistryBridge\Support;

use Fleetbase\RegistryBridge\Exceptions\RegistryException;
use Fleetbase\RegistryBridge\Exceptions\RegistryServiceException;

/**
 * Purchases through the Registry Service checkout (Phase 4).
 *
 * The console asks for a hosted Stripe Checkout session, redirects the buyer to it, and on return polls the
 * hand-off until the service's webhook has recorded the purchase. The licence itself is created by the
 * service; this class only starts the session and normalises the hand-off status for the console.
 */
class ServiceCheckout
{
    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    /** Hand-off statuses the service may report, mapped onto the three the console shows. */
    protected const STATUS_MAP = [
        'pending'    => self::PENDING,
        'open'       => self::PENDING,
        'processing' => self::PENDING,
        'paid'       => self::COMPLETED,
        'complete'   => self::COMPLETED,
        'completed'  => self::COMPLETED,
        'succeeded'  => self::COMPLETED,
        'expired'    => self::FAILED,
        'failed'     => self::FAILED,
        'canceled'   => self::FAILED,
        'cancelled'  => self::FAILED,
    ];

    /**
     * Start a checkout for one listing and return where to send the buyer.
     *
     * @return array{url: string, handoff: string}
     *
     * @throws RegistryException when checkout is off, the service lacks the endpoint, or it refuses
     */
    public static function start(RegistryClient $client, RegistryConfig $config, string $extension, string $returnUrl, ?string $buyerEmail = null): array
    {
        if (!$config->usesService()) {
            throw new RegistryServiceException('Purchases through the registry service need a Registry Service URL.', 409);
        }

        if (!$config->isRegistered()) {
            throw new RegistryServiceException('Register this instance with the registry service before purchasing extensions.', 409);
        }

        $features = [];
        try {
            $features = $client->instance()['features'];
        } catch (RegistryException $e) {
            // An unreachable instance API leaves `auto` mode off; a forced mode still tries
        }

        if (!$config->checkoutEnabled($features)) {
            throw new RegistryServiceException(static::unavailableReason(), 409);
        }

        try {
            return $client->createCheckout([
                'extension'   => $extension,
                'return_url'  => $returnUrl,
                'cancel_url'  => $returnUrl,
                'buyer_email' => $buyerEmail,
            ]);
        } catch (RegistryServiceException $e) {
            if (in_array($e->status(), [404, 405], true)) {
                throw new RegistryServiceException(static::unavailableReason(), 501, [], [], $e);
            }

            throw $e;
        }
    }

    /**
     * The hand-off as the console reads it: `status` (pending, completed or failed), the service's own status,
     * and the extension and receipt when the service sends them.
     *
     * @param array<string, mixed> $body `GET /v1/instances/me/purchases/{handoff}` body
     *
     * @return array{status: string, service_status: string|null, extension: mixed, receipt_url: string|null, message: string|null}
     */
    public static function describe(array $body): array
    {
        $purchase      = is_array($body['purchase'] ?? null) ? $body['purchase'] : [];
        $serviceStatus = $body['status'] ?? $purchase['status'] ?? null;
        $serviceStatus = is_string($serviceStatus) ? strtolower($serviceStatus) : null;

        $receipt = $body['receipt_url'] ?? $purchase['receipt_url'] ?? null;
        $message = $body['message'] ?? $body['error'] ?? null;

        return [
            'status'         => static::STATUS_MAP[$serviceStatus] ?? static::PENDING,
            'service_status' => $serviceStatus,
            'extension'      => $body['extension'] ?? $purchase['extension'] ?? null,
            'receipt_url'    => is_string($receipt) ? $receipt : null,
            'message'        => is_string($message) ? $message : null,
        ];
    }

    public static function unavailableReason(): string
    {
        return 'Purchasing through the registry service is not available yet.';
    }
}
