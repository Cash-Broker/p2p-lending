<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Jobs\SendPasswordResetEmail;
use App\Models\ConsentRecord;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new investor and create their wallet atomically.
     *
     * Wallet creation is wrapped in the same DB transaction as user creation.
     * An account without a wallet is an invalid state in a financial platform —
     * the user can't deposit, invest, or do anything.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'name'         => $request->name,
                'email'        => $request->email,
                'password'     => $request->password,
                'phone'        => $request->input('phone'),
                'account_type' => $request->input('account_type', User::TYPE_INDIVIDUAL),
            ]);

            $user->wallet()->create();

            // For legal-entity registrations: capture only company name + EIK
            // at registration. AML data (address, representative role + ID,
            // PEP status, source of funds, UBO list) is collected in a
            // post-registration KYC workflow — same pattern as individual
            // users uploading their ID document later via /profile.
            if ($user->isLegalEntity()) {
                $user->legalEntityProfile()->create([
                    'legal_name' => $request->input('legal_name'),
                    'eik'        => $request->input('eik'),
                ]);
            }

            // Record legal consent — evidence that user accepted terms at this
            // moment, from this IP, with this browser. Without this record,
            // a user can claim "I never agreed" and we have no defense.
            $consentData = [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'accepted_at' => now(),
            ];

            foreach ([
                [ConsentRecord::TYPE_TERMS, ConsentRecord::CURRENT_TERMS_VERSION],
                [ConsentRecord::TYPE_PRIVACY, ConsentRecord::CURRENT_PRIVACY_VERSION],
                [ConsentRecord::TYPE_RISK, ConsentRecord::CURRENT_RISK_VERSION],
            ] as [$type, $version]) {
                $user->consentRecords()->create([
                    'type' => $type,
                    'version' => $version,
                    ...$consentData,
                ]);
            }

            return $user;
        });

        event(new Registered($user));

        return response()->json([
            'message' => 'Registration successful. Please verify your email.',
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'message' => 'Login successful.',
            'user' => new UserResource($request->user()->load(['wallet', 'legalEntityProfile'])),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // For Sanctum bearer-token clients (mobile, integrations) the session
        // path below is a no-op — we must explicitly delete the access token
        // so it cannot be reused. SPA cookie auth returns a TransientToken
        // here which has no delete(), so we type-check first.
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();
        }

        auth()->guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Revoke ALL access tokens for the current user — "logout from all devices".
     * Useful after suspected token leak or password change.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            $user->tokens()->delete();
        }

        auth()->guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Logged out from all devices.',
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json(new UserResource($request->user()->load(['wallet', 'legalEntityProfile'])));
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // The reset link generation (DB lookup, token persist, mail dispatch) is
        // pushed to the queue. The HTTP response then takes the same time
        // whether the email exists or not — preventing user enumeration via
        // either response body OR response timing.
        SendPasswordResetEmail::dispatch($request->string('email')->toString());

        return response()->json([
            'message' => 'Ако този имейл съществува в системата, ще получите линк за смяна на парола.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['message' => __($status)]);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent.']);
    }
}
