<?php

namespace Fleetbase\RegistryBridge\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\RegistryBridge\Exceptions\RegistryException;
use Fleetbase\RegistryBridge\Exceptions\RegistryServiceException;
use Fleetbase\RegistryBridge\Support\RegistryClient;
use Fleetbase\RegistryBridge\Support\RegistryConfig;
use Fleetbase\RegistryBridge\Support\ServiceCheckout;
use Illuminate\Http\Request;

/**
 * Purchases through the Registry Service checkout (Phase 4): start a hosted checkout, then poll its hand-off.
 */
class ServicePurchaseController extends Controller
{
    /**
     * Start a hosted Stripe Checkout for an extension and return its URL and hand-off id.
     *
     * `return_url` is where Stripe sends the buyer back; the console adds `purchase_handoff` and
     * `extension_id` to it so the Finalizing state can resume.
     *
     * @return \Illuminate\Http\JsonResponse `{url, handoff}`, 409 when checkout is off, 501 when the service lacks it
     */
    public function checkout(Request $request)
    {
        $extension = trim((string) $request->input('extension', ''));
        $returnUrl = trim((string) $request->input('return_url', ''));
        if ($extension === '' || $returnUrl === '') {
            return response()->error('An extension and a return URL are required.', 422);
        }

        $config = RegistryConfig::current();

        try {
            $checkout = ServiceCheckout::start(RegistryClient::fromConfig($config), $config, $extension, $returnUrl, $request->user()?->email);
        } catch (RegistryServiceException $e) {
            return response()->error($e->getMessage(), in_array($e->status(), [409, 501], true) ? $e->status() : ($e->status() >= 400 && $e->status() < 500 ? 422 : 502));
        } catch (RegistryException $e) {
            return response()->error($e->getMessage(), 503);
        }

        return response()->json($checkout);
    }

    /**
     * The status of a checkout hand-off: `pending` until the service's webhook records the purchase, then
     * `completed` or `failed`.
     *
     * @return \Illuminate\Http\JsonResponse `{status, service_status, extension, receipt_url, message}`
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

        return response()->json(ServiceCheckout::describe($body));
    }
}
