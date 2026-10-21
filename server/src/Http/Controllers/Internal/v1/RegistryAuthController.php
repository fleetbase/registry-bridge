<?php

namespace Fleetbase\RegistryBridge\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\User;
use Fleetbase\RegistryBridge\Http\Requests\AddRegistryUserRequest;
use Fleetbase\RegistryBridge\Http\Requests\AuthenticateRegistryUserRequest;
use Fleetbase\RegistryBridge\Http\Requests\RegistryAuthRequest;
use Fleetbase\RegistryBridge\Http\Resources\RegistryUser as RegistryUserResource;
use Fleetbase\RegistryBridge\Models\RegistryDeveloperAccount;
use Fleetbase\RegistryBridge\Models\RegistryExtension;
use Fleetbase\RegistryBridge\Models\RegistryUser;
use Fleetbase\RegistryBridge\Support\Bridge;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RegistryAuthController extends Controller
{
    /**
     * Handle Composer authentication and return a list of unauthorized packages.
     *
     * This function authenticates the user based on the provided token, which may be either the
     * registry token (`registry_token`) or the bridge token (`token`); the legacy `registryToken`
     * key is still accepted. If the token is valid, it returns a list of paid packages that the
     * user's purchaser (company or developer account) has not purchased.
     *
     * @param Request $request the incoming HTTP request containing the token
     *
     * @return \Illuminate\Http\JsonResponse a JSON response containing the status and a list of unauthorized packages
     */
    public function composerAuthentication(Request $request)
    {
        $token = $request->input('registry_token') ?? $request->input('token') ?? $request->input('registryToken');
        if (!$token) {
            return response()->error('No registry token provided for authentication.', 401);
        }

        // Get registry user via token
        $registryUser = RegistryUser::findFromToken($token);
        if (!$registryUser) {
            return response()->error('Invalid registry token provided for authentication.', 401);
        }

        // Init unauthorized extensions
        $unauthorizedExtensionNames = collect();

        // Unless admin the registry user is only allowed access to paid extensions they have purchased
        if ($registryUser->isNotAdmin()) {
            $purchaser = $registryUser->purchaser();

            // Fetch unauthorized extensions (without a purchaser every paid extension is unauthorized)
            $unauthorizedExtensions = RegistryExtension::where('payment_required', true)
            ->when($purchaser, function ($query) use ($purchaser) {
                $query->whereDoesntHave('purchases', function ($query) use ($purchaser) {
                    $query->forPurchaser($purchaser);
                });
            })
            ->whereHas('currentBundle')
            ->with('currentBundle')
            ->get();

            // Map to unathorized to package names
            $unauthorizedExtensionNames = $unauthorizedExtensions->map(function ($registryExtension) {
                $composerJson = $registryExtension->currentBundle->meta['composer.json'] ?? [];

                return $composerJson['name'] ?? null;
            })->filter()->values();
        }

        // Done
        return response()->json([
            'status'               => 'ok',
            'unauthorizedPackages' => $unauthorizedExtensionNames,
        ]);
    }

    /**
     * Authenticates a registry user based on provided credentials.
     *
     * This method attempts to authenticate a user using an identity (which can be either an email or username)
     * and a password. Upon successful authentication, it either retrieves an existing token associated with
     * the user or generates a new one. The method returns the token and user information in JSON format.
     *
     * If authentication fails or a token cannot be generated or retrieved, an error response is returned.
     *
     * @param AuthenticateRegistryUserRequest $request the request object containing identity and password
     *
     * @return RegistryUserResource Returns a JSON resource representing the registry user along with groups and containing the auth token and additional user data.
     *                              Returns an error response if authentication fails or token generation is unsuccessful.
     */
    public function authenticate(AuthenticateRegistryUserRequest $request)
    {
        try {
            $registryUser = $this->authenticateIdentity($request->input('identity'), $request->input('password'));
        } catch (\RuntimeException $e) {
            return response()->error($e->getMessage(), 401);
        }

        return new RegistryUserResource($registryUser);
    }

    /**
     * Adds a new user to the registry with authentication credentials.
     *
     * This method creates a registry user for the authenticated account. It requires an
     * identity (email or username) and password for authentication. Cloud users are linked
     * to their currently active company; registry developer accounts are linked to the
     * developer account. After successful authentication, it returns the registry user
     * (creating one with a scope and expiration date if none exists yet).
     *
     * @param AddRegistryUserRequest $request the request object containing identity and password
     *
     * @return \Illuminate\Http\JsonResponse returns the newly created registry user data in JSON format
     */
    public function addUser(AddRegistryUserRequest $request)
    {
        try {
            $registryUser = $this->authenticateIdentity($request->input('identity'), $request->input('password'));
        } catch (\RuntimeException $e) {
            return response()->error($e->getMessage(), 401);
        }

        return response()->json($registryUser);
    }

    /**
     * Resolve the registry user for an identity (email, phone or username) and password.
     *
     * Cloud users are tried first; if no cloud user matches the credentials, registry developer
     * accounts are tried. A registry user record is created for the account if none exists yet.
     *
     * @throws \RuntimeException when the credentials are invalid or the developer account is not active
     */
    protected function authenticateIdentity(?string $identity, ?string $password): RegistryUser
    {
        if (!$identity || !$password) {
            throw new \RuntimeException('Invalid credentials.');
        }

        // First, try to find a cloud user
        $user = User::where(function ($query) use ($identity) {
            $query->where('email', $identity)->orWhere('phone', $identity)->orWhere('username', $identity);
        })->first();

        if ($user && is_string($user->password) && Auth::checkPassword($password, $user->password)) {
            // Cloud user authentication
            return RegistryUser::firstOrCreate(
                [
                    'company_uuid' => $user->company_uuid,
                    'user_uuid'    => $user->uuid,
                ],
                [
                    'account_type' => 'cloud',
                    'scope'        => '*',
                    'expires_at'   => now()->addYear(),
                    'name'         => $user->public_id . ' developer token',
                ]
            );
        }

        // If not a cloud user, try Registry Developer Account
        $developerAccount = RegistryDeveloperAccount::where(function ($query) use ($identity) {
            $query->where('email', $identity)->orWhere('username', $identity);
        })->first();

        if (!$developerAccount) {
            throw new \RuntimeException('Invalid credentials.');
        }

        if (!$developerAccount->isActive()) {
            throw new \RuntimeException('Account is not active. Please verify your email.');
        }

        if (!is_string($developerAccount->password) || Auth::isInvalidPassword($password, $developerAccount->password)) {
            throw new \RuntimeException('Invalid credentials.');
        }

        // Developer account authentication
        return RegistryUser::firstOrCreate(
            [
                'developer_account_uuid' => $developerAccount->uuid,
            ],
            [
                'account_type' => 'developer',
                'scope'        => '*',
                'expires_at'   => now()->addYear(),
                'name'         => $developerAccount->username . ' developer token',
            ]
        );
    }

    /**
     * Verify a password against the registry user's cloud user, or failing that its developer account.
     */
    protected function verifyRegistryUserPassword(RegistryUser $registryUser, ?string $password): bool
    {
        if (!$password) {
            return false;
        }

        $user = $registryUser->user;
        if ($user && is_string($user->password)) {
            return Auth::checkPassword($password, $user->password);
        }

        $developerAccount = $registryUser->developerAccount;
        if ($developerAccount && $developerAccount->isActive() && is_string($developerAccount->password)) {
            return Auth::checkPassword($password, $developerAccount->password);
        }

        return false;
    }

    /**
     * Checks if a user has access to the registry based on their identity.
     *
     * This function receives a request containing an 'identity' (which could be an email or username) and
     * attempts to find a corresponding user. If the user is found and they have admin privileges, it grants access
     * to the registry by returning a JSON response indicating that access is allowed. If the user doesn't have
     * admin privileges or the user can't be found based on the provided identity, it returns an error response.
     *
     * @param RegistryAuthRequest $request the request containing the user's identity information
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     *                                                                 Returns a JSON response indicating access is allowed if the user is an admin,
     *                                                                 or an error response if the user is not an admin or can't be found
     */
    public function checkAccess(RegistryAuthRequest $request)
    {
        $packageName       = $request->input('package');
        $identity          = $request->input('identity');
        $protectedPackage  = Str::startsWith($packageName, config('registry-bridge.extensions.protected_prefixes'));

        // If no identity and not a protected package allow access
        if (!$identity && !$protectedPackage) {
            return response()->json(['allowed' => true]);
        }

        // if no identity provided
        if (!$identity) {
            return response()->error('No identity provided.', 401);
        }

        // Get registry user via identity
        try {
            $registryUser = RegistryUser::findFromUsername($identity);
        } catch (\Exception $e) {
            return response()->error($e->getMessage(), 401);
        }

        // If registry user is admin allow access
        if ($registryUser->is_admin) {
            return response()->json(['allowed' => true]);
        }

        // Check if package is protected, if so verify user has access to package
        if ($protectedPackage) {
            $extension = RegistryExtension::findByPackageName($packageName);
            if ($extension && $extension->doesntHaveAccess($registryUser)) {
                return response()->error('This package requires payment to access.', 401);
            }
        }

        // For now only admin users can access registry
        return response()->json(['allowed' => true]);
    }

    /**
     * Validates whether a user is allowed to publish or unpublish a specified package in the registry.
     *
     * This function extracts the user's identity, the package name, and the desired action ('publish' or 'unpublish')
     * from the request. It then performs several checks:
     *   1. Verifies that the specified package (extension) exists in the registry.
     *   2. Confirms the existence of the user associated with the provided identity.
     *   3. Checks if the user has administrative privileges.
     *   4. Ensures that the extension's status allows the desired action (either 'approved' or 'published' for publishing,
     *      'published' for unpublishing).
     * If these conditions are met, the function updates the extension's status based on the action and returns a
     * JSON response indicating the action is allowed. If any of these checks fail, it returns an error response.
     *
     * @param RegistryAuthRequest $request the request containing the user's identity, package information, and action
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     *                                                                 Returns a JSON response indicating the action is allowed if all checks pass, or
     *                                                                 an error response if any check fails
     */
    public function checkPublishAllowed(RegistryAuthRequest $request)
    {
        $identity    = $request->input('identity');
        $package     = $request->input('package');
        $action      = $request->input('action', 'publish');
        $force       = $request->boolean('force');
        $password    = $request->input('password');

        // Find user by email or username
        $registryUser = RegistryUser::findFromUsername($identity);
        if (!$registryUser) {
            return response()->error('Attempting to publish extension with invalid user.', 401);
        }

        // If force publish bypass checks, authenticate by cloud user or developer account login
        if ($force === true) {
            if (!$this->verifyRegistryUserPassword($registryUser, $password)) {
                return response()->error('Invalid credentials, unable to force publish.', 401);
            }

            return response()->json(['allowed' => true]);
        }

        // Make sure package is provided
        if (!$package) {
            return response()->error('No package specified for publish.', 401);
        }

        // Find package
        $extension = RegistryExtension::findByPackageName($package);

        if (!$extension) {
            // First time publishing - create extension record owned by the publishing account
            $publisher = $registryUser->publisher();
            if (!$publisher) {
                return response()->error('Unable to determine the publisher for this account.', 403);
            }

            $extension = RegistryExtension::create(array_merge($publisher->toArray(), [
                'uuid'             => (string) Str::uuid(),
                'name'             => $package,
                'package_name'     => $package,
                'company_uuid'     => $registryUser->company_uuid, // Keep for backwards compatibility
                'status'           => 'published',
                'payment_required' => false, // Default to free
            ]));

            return response()->json(['allowed' => true]);
        }

        // Check ownership (publisher type and uuid, falling back to company_uuid for legacy rows)
        if (!$extension->isOwnedBy($registryUser)) {
            return response()->error('You do not own this extension.', 403);
        }

        // Update extension status
        if ($action === 'publish') {
            $extension->update(['status' => 'published']);
            if ($extension->currentBundle) {
                $extension->currentBundle->update(['status' => 'published']);
            }
        } elseif ($action === 'unpublish') {
            $extension->update(['status' => 'unpublished']);
            if ($extension->currentBundle) {
                $extension->currentBundle->update(['status' => 'unpublished']);
            }
        }

        // Passed all checks
        return response()->json(['allowed' => true]);
    }

    /**
     * Creates a registry user by authenticating with the provided password.
     *
     * This method retrieves the current authenticated user and checks the provided password.
     * If the password is valid, it logs in to the npm registry using the user's credentials,
     * retrieves the authentication token, and associates it with the user. The registry token
     * is stored in the database for the user's current session.
     *
     * @param Request $request the incoming HTTP request containing the user's password
     *
     * @return \Illuminate\Http\JsonResponse the JSON response containing the created RegistryUser or an error message
     */
    public function createRegistryUser(Request $request)
    {
        set_time_limit(120 * 6);
        $password = $request->input('password');
        if (!$password) {
            return response()->error('Password is required.');
        }

        // Get current user
        $user = Auth::getUserFromSession();
        if (!$user) {
            return response()->error('No user authenticated.');
        }

        // Authenticate user with password
        if (Auth::isInvalidPassword($password, $user->password)) {
            return response()->error('Invalid credentials.', 401);
        }

        // Create registry user
        try {
            $registryUser = Bridge::loginWithUser($user, $password);
        } catch (\Throwable $e) {
            return response()->json($e->getMessage());
        }

        return response()->json($registryUser);
    }

    /**
     * Retrieves all registry tokens for the current company.
     *
     * This method queries the `RegistryUser` model to get all registry tokens
     * associated with the current company's UUID from the session. It also includes
     * user details for each registry token and returns the data as a JSON response.
     *
     * @return \Illuminate\Http\JsonResponse the JSON response containing a list of registry tokens with user details
     */
    public function getRegistryTokens()
    {
        $registryUsers = RegistryUser::select(
            ['uuid', 'user_uuid', 'company_uuid', 'token', 'registry_token', 'expires_at', 'created_at']
        )->where('company_uuid', session('company'))->with(
            [
                'user' => function ($query) {
                    $query->select(['uuid', 'company_uuid', 'name', 'email']);
                },
            ]
        )->get();

        return response()->json($registryUsers);
    }

    /**
     * Deletes a specific registry token by its UUID.
     *
     * This method deletes a registry token identified by its UUID. If the registry token
     * does not exist, it returns an error response. If successful, it returns a JSON response
     * with a status indicating the deletion was successful.
     *
     * @param string $id the UUID of the registry token to be deleted
     *
     * @return \Illuminate\Http\JsonResponse the JSON response indicating the status of the deletion
     */
    public function deleteRegistryToken(string $id)
    {
        $registryUser = RegistryUser::where('uuid', $id)->first();
        if (!$registryUser) {
            return response()->error('Registry token does not exist.');
        }

        $registryUser->delete();

        return response()->json(['status' => 'ok']);
    }
}
