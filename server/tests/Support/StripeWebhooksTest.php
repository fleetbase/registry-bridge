<?php

use Fleetbase\RegistryBridge\Support\Purchaser;
use Fleetbase\RegistryBridge\Support\StripeWebhooks;
use Stripe\Exception\SignatureVerificationException;

function stripeSignatureHeader(string $payload, string $secret, ?int $timestamp = null): string
{
    $timestamp = $timestamp ?? time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

    return 't=' . $timestamp . ',v1=' . $signature;
}

function checkoutSessionPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'id'             => 'cs_test_123',
        'object'         => 'checkout.session',
        'status'         => 'complete',
        'payment_status' => 'paid',
        'payment_intent' => 'pi_test_123',
        'amount_total'   => 4900,
        'metadata'       => [
            'extension_uuid' => 'ext-1',
            'purchaser_type' => Purchaser::TYPE_COMPANY,
            'purchaser_uuid' => 'company-1',
        ],
    ], $overrides);
}

function checkoutCompletedEvent(array $sessionOverrides = []): string
{
    return json_encode([
        'id'      => 'evt_test_123',
        'object'  => 'event',
        'type'    => 'checkout.session.completed',
        'created' => time(),
        'data'    => ['object' => checkoutSessionPayload($sessionOverrides)],
    ]);
}

test('a correctly signed webhook payload is accepted', function () {
    $secret  = 'whsec_test';
    $payload = checkoutCompletedEvent();

    $event = StripeWebhooks::constructEvent($payload, stripeSignatureHeader($payload, $secret), $secret);

    expect($event->type)->toBe('checkout.session.completed')
        ->and($event->data->object->id)->toBe('cs_test_123')
        ->and($event->data->object->metadata->extension_uuid)->toBe('ext-1');
});

test('a payload signed with the wrong secret is rejected', function () {
    $payload = checkoutCompletedEvent();

    StripeWebhooks::constructEvent($payload, stripeSignatureHeader($payload, 'whsec_other'), 'whsec_test');
})->throws(SignatureVerificationException::class);

test('a tampered payload is rejected', function () {
    $secret   = 'whsec_test';
    $payload  = checkoutCompletedEvent();
    $header   = stripeSignatureHeader($payload, $secret);
    $tampered = str_replace('company-1', 'company-2', $payload);

    StripeWebhooks::constructEvent($tampered, $header, $secret);
})->throws(SignatureVerificationException::class);

test('a stale signature outside the tolerance window is rejected', function () {
    $secret  = 'whsec_test';
    $payload = checkoutCompletedEvent();

    StripeWebhooks::constructEvent($payload, stripeSignatureHeader($payload, $secret, time() - 3600), $secret);
})->throws(SignatureVerificationException::class);

test('a missing signature header is rejected', function () {
    StripeWebhooks::constructEvent(checkoutCompletedEvent(), null, 'whsec_test');
})->throws(UnexpectedValueException::class);

test('a missing webhook secret is rejected', function () {
    $payload = checkoutCompletedEvent();

    StripeWebhooks::constructEvent($payload, stripeSignatureHeader($payload, 'whsec_test'), null);
})->throws(UnexpectedValueException::class);

test('a paid checkout session for a company maps to a purchase record', function () {
    $purchase = StripeWebhooks::purchaseFromCheckoutSession(checkoutSessionPayload());

    expect($purchase)->toBe([
        'match' => [
            'purchaser_type' => Purchaser::TYPE_COMPANY,
            'purchaser_uuid' => 'company-1',
            'extension_uuid' => 'ext-1',
        ],
        'attributes' => [
            'stripe_checkout_session_id' => 'cs_test_123',
            'stripe_payment_intent_id'   => 'pi_test_123',
            'locked_price'               => 4900,
            'company_uuid'               => 'company-1',
        ],
    ]);
});

test('a paid checkout session for a developer account has no company uuid', function () {
    $purchase = StripeWebhooks::purchaseFromCheckoutSession(checkoutSessionPayload([
        'metadata' => [
            'purchaser_type' => Purchaser::TYPE_DEVELOPER_ACCOUNT,
            'purchaser_uuid' => 'dev-1',
        ],
    ]));

    expect($purchase['match'])->toBe([
        'purchaser_type' => Purchaser::TYPE_DEVELOPER_ACCOUNT,
        'purchaser_uuid' => 'dev-1',
        'extension_uuid' => 'ext-1',
    ])->and($purchase['attributes']['company_uuid'])->toBeNull();
});

test('an expanded payment intent object is reduced to its id', function () {
    $purchase = StripeWebhooks::purchaseFromCheckoutSession(checkoutSessionPayload([
        'payment_intent' => ['id' => 'pi_expanded', 'object' => 'payment_intent'],
    ]));

    expect($purchase['attributes']['stripe_payment_intent_id'])->toBe('pi_expanded');
});

test('sessions that are not complete do not create purchases', function () {
    expect(StripeWebhooks::purchaseFromCheckoutSession(checkoutSessionPayload(['status' => 'open'])))->toBeNull()
        ->and(StripeWebhooks::purchaseFromCheckoutSession(checkoutSessionPayload(['status' => 'expired'])))->toBeNull();
});

test('sessions that are not yet paid do not create purchases', function () {
    expect(StripeWebhooks::purchaseFromCheckoutSession(checkoutSessionPayload(['payment_status' => 'unpaid'])))->toBeNull();
});

test('sessions with no payment required still create purchases', function () {
    expect(StripeWebhooks::purchaseFromCheckoutSession(checkoutSessionPayload(['payment_status' => 'no_payment_required'])))->not->toBeNull();
});

test('sessions without purchase metadata do not create purchases', function () {
    expect(StripeWebhooks::purchaseFromCheckoutSession(checkoutSessionPayload(['metadata' => ['extension_uuid' => null]])))->toBeNull()
        ->and(StripeWebhooks::purchaseFromCheckoutSession(checkoutSessionPayload(['metadata' => ['purchaser_uuid' => null]])))->toBeNull()
        ->and(StripeWebhooks::purchaseFromCheckoutSession(checkoutSessionPayload(['metadata' => ['purchaser_type' => 'App\\Models\\User']])))->toBeNull();
});
