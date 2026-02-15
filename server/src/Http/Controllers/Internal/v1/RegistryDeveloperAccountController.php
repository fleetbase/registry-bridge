<?php

namespace Fleetbase\RegistryBridge\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\RegistryBridge\Models\RegistryDeveloperAccount;
use Fleetbase\RegistryBridge\Models\RegistryUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RegistryDeveloperAccountController extends Controller
{
    /**
     * Register a new Registry Developer Account.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|min:3|max:255|unique:registry_developer_accounts,username|regex:/^[a-zA-Z0-9_-]+$/',
            'email'    => 'required|email|unique:registry_developer_accounts,email',
            'password' => 'required|string|min:8',
            'name'     => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $account = RegistryDeveloperAccount::create([
            'uuid'               => (string) Str::uuid(),
            'username'           => $validated['username'],
            'email'              => $validated['email'],
            'password'           => Hash::make($validated['password']),
            'name'               => $validated['name'] ?? $validated['username'],
            'status'             => 'pending_verification',
            'verification_token' => Str::random(64),
        ]);

        // Send verification email
        $this->sendVerificationEmail($account);

        return response()->json([
            'status'  => 'success',
            'message' => 'Account created successfully. Please check your email to verify your account.',
            'account' => [
                'uuid'     => $account->uuid,
                'username' => $account->username,
                'email'    => $account->email,
            ],
        ], 201);
    }

    /**
     * Verify email address.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyEmail(Request $request)
    {
        $token = $request->input('token');

        if (!$token) {
            return response()->error('Verification token is required.', 400);
        }

        $account = RegistryDeveloperAccount::where('verification_token', $token)->first();

        if (!$account) {
            return response()->error('Invalid verification token.', 400);
        }

        if ($account->isActive()) {
            return response()->json([
                'status'  => 'success',
                'message' => 'Email already verified.',
            ]);
        }

        $account->markEmailAsVerified();

        return response()->json([
            'status'  => 'success',
            'message' => 'Email verified successfully. You can now log in.',
        ]);
    }

    /**
     * Resend verification email.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function resendVerification(Request $request)
    {
        $email = $request->input('email');

        if (!$email) {
            return response()->error('Email is required.', 400);
        }

        $account = RegistryDeveloperAccount::where('email', $email)->first();

        if (!$account) {
            return response()->error('Account not found.', 404);
        }

        if ($account->isActive()) {
            return response()->json([
                'status'  => 'success',
                'message' => 'Email already verified.',
            ]);
        }

        // Generate new token
        $account->generateVerificationToken();

        // Resend verification email
        $this->sendVerificationEmail($account);

        return response()->json([
            'status'  => 'success',
            'message' => 'Verification email sent.',
        ]);
    }

    /**
     * Get account profile.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function profile(Request $request)
    {
        // This would require middleware to authenticate the user
        // For now, we'll accept a token parameter
        $token = $request->bearerToken() ?? $request->input('token');

        if (!$token) {
            return response()->error('Authentication required.', 401);
        }

        $registryUser = RegistryUser::findFromToken($token);

        if (!$registryUser || $registryUser->account_type !== 'developer') {
            return response()->error('Invalid token or not a developer account.', 403);
        }

        $account = $registryUser->developerAccount;

        if (!$account) {
            return response()->error('Developer account not found.', 404);
        }

        return response()->json([
            'uuid'          => $account->uuid,
            'username'      => $account->username,
            'email'         => $account->email,
            'name'          => $account->name,
            'avatar_url'    => $account->avatar_url,
            'github_username' => $account->github_username,
            'website'       => $account->website,
            'bio'           => $account->bio,
            'status'        => $account->status,
            'email_verified_at' => $account->email_verified_at,
            'created_at'    => $account->created_at,
        ]);
    }

    /**
     * Update account profile.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(Request $request)
    {
        $token = $request->bearerToken() ?? $request->input('token');

        if (!$token) {
            return response()->error('Authentication required.', 401);
        }

        $registryUser = RegistryUser::findFromToken($token);

        if (!$registryUser || $registryUser->account_type !== 'developer') {
            return response()->error('Invalid token or not a developer account.', 403);
        }

        $account = $registryUser->developerAccount;

        if (!$account) {
            return response()->error('Developer account not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'name'            => 'nullable|string|max:255',
            'avatar_url'      => 'nullable|url|max:500',
            'github_username' => 'nullable|string|max:255',
            'website'         => 'nullable|url|max:500',
            'bio'             => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $account->update($validator->validated());

        return response()->json([
            'status'  => 'success',
            'message' => 'Profile updated successfully.',
            'account' => $account,
        ]);
    }

    /**
     * Send verification email to the account.
     *
     * @param RegistryDeveloperAccount $account
     *
     * @return void
     */
    private function sendVerificationEmail(RegistryDeveloperAccount $account)
    {
        // TODO: Implement email sending logic
        // This would typically use Laravel's Mail facade to send an email
        // with a verification link containing the verification token
        
        // Example:
        // Mail::to($account->email)->send(new VerifyRegistryDeveloperAccount($account));
        
        // For now, we'll just log it
        logger()->info('Verification email would be sent to: ' . $account->email);
        logger()->info('Verification token: ' . $account->verification_token);
        logger()->info('Verification URL: ' . config('app.url') . '/~registry/v1/developer-account/verify?token=' . $account->verification_token);
    }
}
