<?php

namespace Fleetbase\RegistryBridge\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\VerificationCode;
use Fleetbase\RegistryBridge\Models\RegistryDeveloperAccount;
use Fleetbase\RegistryBridge\Models\RegistryUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
     * Verify email address using verification code.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyEmail(Request $request)
    {
        $code  = $request->input('code');
        $email = $request->input('email');

        if (!$code || !$email) {
            return response()->error('Verification code and email are required.', 400);
        }

        // Find the account
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

        // Find the verification code
        $verificationCode = VerificationCode::where('subject_uuid', $account->uuid)
            ->where('subject_type', RegistryDeveloperAccount::class)
            ->where('for', 'registry_developer_account_verification')
            ->where('code', $code)
            ->where('status', 'pending')
            ->first();

        if (!$verificationCode) {
            return response()->error('Invalid or expired verification code.', 400);
        }

        // Check if code is expired
        if ($verificationCode->isExpired()) {
            return response()->error('Verification code has expired.', 400);
        }

        // Mark as verified
        $account->markEmailAsVerified();
        $verificationCode->update(['status' => 'used']);

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
        try {
            VerificationCode::generateEmailVerificationFor(
                $account,
                'registry_developer_account_verification',
                [
                    'subject' => 'Verify your Registry Developer Account',
                    'content' => function ($verificationCode) use ($account) {
                        return "Hello {$account->name},\n\n" .
                               "Thank you for registering a Registry Developer Account!\n\n" .
                               "Your verification code is: {$verificationCode->code}\n\n" .
                               "To verify your account, run this command in your terminal:\n" .
                               "flb verify -e {$account->email}\n\n" .
                               "Then enter the verification code when prompted.\n\n" .
                               "This code will expire in 1 hour.\n\n" .
                               "If you did not create this account, please ignore this email.";
                    },
                ]
            );
        } catch (\Exception $e) {
            // Log the error but don't fail registration
            logger()->error('Failed to send verification email', [
                'email' => $account->email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
