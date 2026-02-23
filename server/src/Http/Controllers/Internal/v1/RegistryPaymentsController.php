<?php

namespace Fleetbase\RegistryBridge\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\RegistryBridge\Models\RegistryExtension;
use Fleetbase\RegistryBridge\Models\RegistryExtensionPurchase;
use Fleetbase\RegistryBridge\Support\Utils;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Handles payment processing and Stripe account management for registry extensions.
 *
 * This controller provides functionalities such as checking if a company has a Stripe Connect account,
 * creating Stripe accounts, managing Stripe account sessions, and handling Stripe Checkout sessions.
 */
class RegistryPaymentsController extends Controller
{
    /**
     * Checks if the currently authenticated company has an associated Stripe Connect account.
     *
     * This method verifies if the authenticated company has a Stripe Connect ID that starts with 'acct_'.
     *
     * @return \Illuminate\Http\JsonResponse returns a JSON response indicating the presence of a Stripe Connect account
     */
    public function hasStripeConnectAccount()
    {
        $company = Auth::getCompany();
        if ($company) {
            return response()->json([
                'hasStripeConnectAccount' => !empty($company->stripe_connect_id) && Str::startsWith($company->stripe_connect_id, 'acct_'),
            ]);
        }

        return response()->json([
            'hasStripeConnectAccount' => false,
        ]);
    }

    /**
     * Creates a new Stripe account for the currently authenticated company and stores the account ID.
     *
     * This method utilizes the Fleetbase utility class to create a Stripe Express account and saves the
     * Stripe account ID to the current company's profile. In case of failure, it returns an error.
     *
     * @return \Illuminate\Http\JsonResponse returns the Stripe account ID or an error message in JSON format
     */
    public function getStripeAccount()
    {
        $stripe = Utils::getStripeClient();

        try {
            $account = $stripe->accounts->create([
                'controller' => [
                    'stripe_dashboard' => [
                        'type' => 'express',
                    ],
                    'fees' => [
                        'payer' => 'application',
                    ],
                    'losses' => [
                        'payments' => 'application',
                    ],
                ],
            ]);

            // Save account ID to current company session
            $company = Auth::getCompany();
            if ($company) {
                $company->update(['stripe_connect_id' => $account->id]);
            }

            return response()->json(['account' => $account->id]);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }
    }

    /**
     * Creates a Stripe account session for account onboarding or management.
     *
     * This method creates a session for the current company's Stripe account, allowing for onboarding or management activities.
     * It accepts an 'account' parameter from the request, defaulting to the company's stored Stripe Connect ID if not provided.
     *
     * @param Request $request the incoming HTTP request containing optional 'account' parameter
     *
     * @return \Illuminate\Http\JsonResponse returns a JSON response with the session's client secret or an error message
     */
    public function getStripeAccountSession(Request $request)
    {
        $stripe  = Utils::getStripeClient();
        $company = Auth::getCompany();

        try {
            $accountSession = $stripe->accountSessions->create([
                'account'    => $request->input('account', $company->stripe_connect_id),
                'components' => [
                    'account_onboarding' => [
                        'enabled' => true,
                    ],
                ],
            ]);

            return response()->json([
                'clientSecret' => $accountSession->client_secret,
            ]);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }
    }

    /**
     * Creates a Stripe Checkout session for a specified registry extension.
     *
     * This method initializes a checkout session for purchasing a registry extension identified by a UUID.
     * It requires a 'uri' for redirection after the checkout and an 'extension' UUID to identify the product.
     *
     * @param Request $request the incoming HTTP request with 'uri' and 'extension' parameters
     *
     * @return \Illuminate\Http\JsonResponse returns the checkout session's client secret or an error message
     */
    public function createStripeCheckoutSession(Request $request)
    {
        $redirectUri       = $request->input('uri');
        $extension         = RegistryExtension::where('uuid', $request->input('extension'))->first();
        if (!$extension) {
            return response()->error('The extension you attempted to purchase does not exist.');
        }

        try {
            $checkoutSession = $extension->createStripeCheckoutSession($redirectUri);
        } catch (\Throwable $e) {
            return response()->error($e->getMessage());
        }

        return response()->json(['clientSecret' => $checkoutSession->client_secret]);
    }

    /**
     * Retrieves the status of an ongoing Stripe Checkout session.
     *
     * This method checks the status of a Stripe Checkout session associated with a registry extension purchase.
     * It ensures the extension exists and checks if it has already been purchased. It then retrieves and returns
     * the checkout session status or creates a purchase record if the session is complete.
     *
     * @param Request $request the incoming HTTP request containing 'extension' and 'checkout_session_id'
     *
     * @return \Illuminate\Http\JsonResponse returns the checkout session status or an error message
     */
    public function getStripeCheckoutSessionStatus(Request $request)
    {
        $extension = RegistryExtension::where('uuid', $request->input('extension'))->first();
        if (!$extension) {
            return response()->error('The extension you attempted to purchase does not exist.');
        }

        // Flush cache for extension
        if (method_exists($extension, 'flushCache')) {
            $extension->flushCache();
        }

        // Determine purchaser (Company or RegistryDeveloperAccount)
        $purchaser = null;
        $purchaserType = null;
        $purchaserUuid = null;

        // Check if authenticated via session (cloud user)
        if (session('company')) {
            $purchaserUuid = session('company');
            $purchaserType = 'Fleetbase\\Models\\Company';
        } 
        // Check if authenticated via bearer token (developer account)
        elseif ($request->bearerToken()) {
            $registryUser = \Fleetbase\RegistryBridge\Models\RegistryUser::where('token', $request->bearerToken())->first();
            if ($registryUser && $registryUser->developer_account_uuid) {
                $purchaserUuid = $registryUser->developer_account_uuid;
                $purchaserType = 'Fleetbase\\RegistryBridge\\Models\\RegistryDeveloperAccount';
            }
        }

        if (!$purchaserUuid || !$purchaserType) {
            return response()->error('Unable to identify purchaser. Please ensure you are logged in.');
        }

        // Check if already purchased
        $purchaseRecordExists = RegistryExtensionPurchase::where([
            'purchaser_uuid' => $purchaserUuid,
            'purchaser_type' => $purchaserType,
            'extension_uuid' => $extension->uuid
        ])->exists();
        
        if ($purchaseRecordExists) {
            return response()->json(['status' => 'purchase_complete', 'extension' => $extension]);
        }

        $stripe = Utils::getStripeClient();
        try {
            $session = $stripe->checkout->sessions->retrieve($request->input('checkout_session_id'));
            if (isset($session->status) && $session->status === 'complete') {
                RegistryExtensionPurchase::firstOrCreate(
                    [
                        'purchaser_uuid' => $purchaserUuid,
                        'purchaser_type' => $purchaserType,
                        'extension_uuid' => $extension->uuid,
                    ],
                    [
                        'stripe_checkout_session_id' => $session->id,
                        'stripe_payment_intent_id'   => $session->payment_intent,
                        'locked_price'               => $session->amount_total,
                        // Keep company_uuid for backward compatibility if it's a company
                        'company_uuid'               => $purchaserType === 'Fleetbase\\Models\\Company' ? $purchaserUuid : null,
                    ]
                );
            }

            // Flush cache for extension
            if (method_exists($extension, 'flushCache')) {
                $extension->flushCache();
            }

            return response()->json(['status' => $session->status, 'extension' => $extension]);
        } catch (\Error $e) {
            return response()->error($e->getMessage());
        }
    }

    public function getAuthorReceivedPayments(Request $request)
    {
        $limit     = $request->input('limit', 30);
        $query     = RegistryExtensionPurchase::whereHas(
            'extension',
            function ($query) {
                $query->where('company_uuid', session('company'));
            }
        )->with(
            [
                'extension' => function ($query) {
                    $query->select(['uuid', 'public_id', 'name', 'category_uuid']);
                    $query->with(['category']);
                },
                'company' => function ($query) {
                    $query->select(['uuid', 'name'])->withoutGlobalScopes();
                },
            ]
        );

        // Handle sorting
        app(RegistryExtensionPurchase::class)->applySorts($request, $query);

        $payments                   = $query->fastPaginate($limit);
        $totalPurchaseAmount        = $query->get()->sum('locked_price');

        return FleetbaseResource::collection($payments)->additional(['total_amount' => $totalPurchaseAmount]);
    }

    /**
     * Creates a Stripe account session for account management.
     *
     * This method creates a session that allows connected accounts to manage their account details,
     * including updating bank account information, business details, and other settings.
     *
     * @param Request $request the incoming HTTP request
     *
     * @return \Illuminate\Http\JsonResponse returns a JSON response with the session's client secret or an error message
     */
    public function createAccountManagementSession(Request $request)
    {
        $stripe  = Utils::getStripeClient();
        $company = Auth::getCompany();

        if (!$company || !$company->stripe_connect_id) {
            return response()->error('Stripe Connect account not found for this company.');
        }

        try {
            $accountSession = $stripe->accountSessions->create([
                'account'    => $company->stripe_connect_id,
                'components' => [
                    'account_management' => [
                        'enabled' => true,
                        'features' => [
                            'external_account_collection' => true,
                        ],
                    ],
                ],
            ]);

            return response()->json([
                'clientSecret' => $accountSession->client_secret,
            ]);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }
    }
}
