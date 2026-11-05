<?php

namespace Fleetbase\RegistryBridge\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\RegistryBridge\Exceptions\RegistryException;
use Fleetbase\RegistryBridge\Exceptions\RegistryServiceException;
use Fleetbase\RegistryBridge\Support\RegistryClient;
use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Fleetbase\RegistryBridge\Support\ServiceCheckout;
use Fleetbase\RegistryBridge\Support\ServiceReceipts;
use Illuminate\Http\Request;

/**
 * Purchases through the Registry Service checkout (Phase 4): start a hosted checkout, then poll its hand-off.
 */
class ServicePurchaseController extends Controller
{
    /**
     * Start a hosted Stripe Checkout for an extension and return its URL and hand-off id.
     *
     * `return_url` is where Stripe sends the buyer back with `handoff=<id>` appended, `cancel_url` (default:
     * the return URL) where it sends a buyer who cancels, with `cancelled=1` as well.
     *
     * @return \Illuminate\Http\JsonResponse `{url, handoff, status, amount, currency}`; the service's refusal
     *                                       (409 already licensed, 422 not purchasable here, 503 no Stripe) passes
     *                                       through with its message, 501 when the service has no checkout
     */
    public function checkout(Request $request)
    {
        $extension = trim((string) $request->input('extension', ''));
        $returnUrl = trim((string) $request->input('return_url', ''));
        $cancelUrl = trim((string) $request->input('cancel_url', ''));
        if ($extension === '' || $returnUrl === '') {
            return response()->error('An extension and a return URL are required.', 422);
        }

        $config = RegistryConfig::current();

        try {
            $checkout = ServiceCheckout::start(RegistryClient::fromConfig($config), $config, $extension, $returnUrl, $cancelUrl ?: null, $request->user()?->email);
        } catch (RegistryServiceException $e) {
            return response()->error($e->getMessage(), static::status($e));
        } catch (RegistryException $e) {
            return response()->error($e->getMessage(), 503);
        }

        return response()->json($checkout);
    }

    /**
     * The status of a checkout hand-off: `pending` while it is `open`, `completed` once the service's webhook
     * recorded the purchase (its receipt is kept for Purchased), or `failed` when it `expired`.
     *
     * @return \Illuminate\Http\JsonResponse see `ServiceCheckout::describe()`
     */
    public function handoff(string $handoff)
    {
        $config = RegistryConfig::current();

        try {
            $body = RegistryClient::fromConfig($config)->purchase($handoff);
        } catch (RegistryServiceException $e) {
            return response()->error($e->getMessage(), $e->status() === 404 ? 404 : ($e->status() >= 400 && $e->status() < 500 ? 422 : 502));
        } catch (RegistryException $e) {
            return response()->error($e->getMessage(), 503);
        }

        $handoff = ServiceCheckout::describe($body);
        ServiceReceipts::remember($config, $handoff);

        return response()->json($handoff);
    }

    /**
     * HTTP status for a refused checkout: the service's own 4xx and 501/503 pass through, other failures are 502.
     */
    protected static function status(RegistryServiceException $e): int
    {
        $status = $e->status();

        return ($status >= 400 && $status < 500) || in_array($status, [501, 503], true) ? $status : 502;
    }
}
