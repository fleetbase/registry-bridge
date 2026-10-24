<?php

namespace Fleetbase\RegistryBridge\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Stripe receipts of purchases made from this console, keyed by extension id.
 *
 * The service puts the receipt on the completed checkout hand-off; its `GET /v1/instances/me` licences
 * do not carry it yet. The receipt is kept here when the hand-off completes so Purchased can link it, and
 * a receipt the service sends on the licence always wins (`ServiceCatalog::withReceipt`).
 */
class ServiceReceipts
{
    protected const CACHE_PREFIX = 'registry-bridge:receipts';

    /**
     * Keep the receipt of a completed hand-off (`ServiceCheckout::describe()` shape).
     *
     * @param array<string, mixed> $handoff
     */
    public static function remember(RegistryConfig $config, array $handoff): void
    {
        $extension = is_array($handoff['extension'] ?? null) ? ($handoff['extension']['id'] ?? null) : null;
        if (!is_string($extension) || $extension === '' || ($handoff['status'] ?? null) !== ServiceCheckout::COMPLETED) {
            return;
        }

        $purchase             = is_array($handoff['purchase'] ?? null) ? $handoff['purchase'] : [];
        $receipts             = static::all($config);
        $receipts[$extension] = array_filter([
            'receipt_url'  => $handoff['receipt_url'] ?? ($purchase['receipt_url'] ?? null),
            'purchase'     => $purchase['id'] ?? null,
            'amount_total' => $purchase['amount_total'] ?? null,
            'currency'     => $purchase['currency'] ?? null,
            'purchased_at' => $purchase['created_at'] ?? null,
        ], fn ($value) => $value !== null);

        Cache::forever(static::key($config), $receipts);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(RegistryConfig $config): array
    {
        $receipts = Cache::get(static::key($config), []);

        return is_array($receipts) ? array_filter($receipts, 'is_array') : [];
    }

    protected static function key(RegistryConfig $config): string
    {
        return static::CACHE_PREFIX . ':' . sha1((string) $config->serviceUrl() . '|' . ($config->instance()['id'] ?? ''));
    }
}
