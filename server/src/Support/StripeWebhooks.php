<?php

namespace Fleetbase\RegistryBridge\Support;

use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Framework-free helpers for handling Stripe webhooks for extension purchases.
 */
final class StripeWebhooks
{
    /**
     * Checkout events that indicate a session has been paid for.
     */
    public const CHECKOUT_PAID_EVENTS = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
    ];

    /**
     * Payment statuses on a Checkout Session that count as paid.
     */
    public const PAID_PAYMENT_STATUSES = ['paid', 'no_payment_required'];

    /**
     * Verify a webhook payload against its `Stripe-Signature` header and build the event.
     *
     * @throws \UnexpectedValueException      when the secret or signature header is missing, or the payload is not valid JSON
     * @throws SignatureVerificationException when the signature does not match
     */
    public static function constructEvent(string $payload, ?string $signatureHeader, ?string $secret, int $tolerance = Webhook::DEFAULT_TOLERANCE): Event
    {
        if (empty($secret)) {
            throw new \UnexpectedValueException('Stripe webhook secret is not configured.');
        }

        if (empty($signatureHeader)) {
            throw new \UnexpectedValueException('Missing Stripe-Signature header.');
        }

        return Webhook::constructEvent($payload, $signatureHeader, $secret, $tolerance);
    }

    /**
     * Derive the purchase record for a paid Checkout Session.
     *
     * The session must be `complete` with a paid payment status and carry the
     * `extension_uuid`, `purchaser_type` and `purchaser_uuid` metadata set by
     * `RegistryExtension::createStripeCheckoutSession()`.
     *
     * Returns the columns identifying the purchase (`match`) and the remaining columns to
     * store (`attributes`), or null when the session should not produce a purchase.
     *
     * @param array<string, mixed> $session the Checkout Session as an array
     *
     * @return array{match: array<string, string>, attributes: array<string, mixed>}|null
     */
    public static function purchaseFromCheckoutSession(array $session): ?array
    {
        if (($session['status'] ?? null) !== 'complete') {
            return null;
        }

        if (!in_array($session['payment_status'] ?? null, self::PAID_PAYMENT_STATUSES, true)) {
            return null;
        }

        $metadata      = (array) ($session['metadata'] ?? []);
        $extensionUuid = $metadata['extension_uuid'] ?? null;
        $purchaser     = Purchaser::fromType($metadata['purchaser_type'] ?? null, $metadata['purchaser_uuid'] ?? null);
        if (empty($extensionUuid) || !is_string($extensionUuid) || !$purchaser) {
            return null;
        }

        $paymentIntent = $session['payment_intent'] ?? null;
        if (is_array($paymentIntent)) {
            $paymentIntent = $paymentIntent['id'] ?? null;
        }

        return [
            'match'      => array_merge($purchaser->toArray(), ['extension_uuid' => $extensionUuid]),
            'attributes' => [
                'stripe_checkout_session_id' => $session['id'] ?? null,
                'stripe_payment_intent_id'   => is_string($paymentIntent) ? $paymentIntent : null,
                'locked_price'               => $session['amount_total'] ?? null,
                // Keep company_uuid for backward compatibility when the purchaser is a company
                'company_uuid'               => $purchaser->isCompany() ? $purchaser->uuid() : null,
            ],
        ];
    }
}
