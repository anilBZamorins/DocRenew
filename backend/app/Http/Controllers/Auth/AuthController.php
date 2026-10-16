<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * FSD 3.4 Authentication & Session Management.
 *
 * Login issues two Sanctum personal access tokens rather than one:
 *   - an "access" token, abilities ['access'], 30-minute expiry
 *   - a "refresh" token, abilities ['refresh'], 14-day expiry
 * /refresh exchanges a valid refresh token for a new access token
 * without re-prompting for a password, mirroring the JWT access +
 * refresh token pattern described in the FSD without requiring a
 * separate JWT package on top of Sanctum.
 */
class AuthController extends Controller
{
    private const ACCESS_TTL_MINUTES = 30;
    private const REFRESH_TTL_DAYS = 14;

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'tenant_id' => ['required', 'uuid'],
        ]);

        $throttleKey = strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Please try again in a minute.',
            ]);
        }

        $user = User::where('tenant_id', $credentials['tenant_id'])
            ->where('email', $credentials['email'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->getAuthPassword())) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if ($user->status === UserStatus::Invited) {
            throw ValidationException::withMessages([
                'email' => 'This account has not been activated yet. Check your invitation email.',
            ]);
        }

        if ($user->status === UserStatus::Suspended) {
            throw ValidationException::withMessages([
                'email' => 'This account has been suspended.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json($this->issueTokenPair($user));
    }

    public function refresh(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token || ! in_array('refresh', $token->abilities, true)) {
            abort(403, 'A refresh token is required for this action.');
        }

        $user = $request->user();

        if (! $user->isActive()) {
            abort(403, 'This account has been suspended.');
        }

        // Rotate: delete the used refresh token and the access tokens issued
        // alongside it, then issue a fresh pair.
        $user->tokens()->delete();

        return response()->json($this->issueTokenPair($user));
    }

    public function logout(Request $request): JsonResponse
    {
        // Revoke every token for this user (all devices), per FSD 3.4
        // "revocable (e.g. on password change or admin-forced logout)".
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('employeeProfile');

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'tenant_id' => $user->tenant_id,
            'employee_profile' => $user->employeeProfile,
            'last_login_at' => $user->last_login_at,
        ]);
    }

    private function issueTokenPair(User $user): array
    {
        $access = $user->createToken(
            'access',
            ['access'],
            now()->addMinutes(self::ACCESS_TTL_MINUTES)
        );

        $refresh = $user->createToken(
            'refresh',
            ['refresh'],
            now()->addDays(self::REFRESH_TTL_DAYS)
        );

        return [
            'access_token' => $access->plainTextToken,
            'refresh_token' => $refresh->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL_MINUTES * 60,
        ];
    }
}
