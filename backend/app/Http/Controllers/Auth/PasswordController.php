<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * FSD 3.4:
 *   - Password policy: min 10 chars, at least one letter and one number.
 *   - Password reset: time-limited, single-use token emailed to the
 *     account's registered address.
 *   - Account lifecycle: Invited (email sent) -> Activated (user sets
 *     password) -> Active.
 *
 * Laravel's stock PasswordBroker keys password_reset_tokens by email
 * alone. DocRenew's uniqueness constraint is (tenant_id, email), so the
 * same email can exist under two different tenants — an email-only key
 * would let a token issued for one tenant reset a different tenant's
 * same-email account. This controller therefore reads/writes
 * password_reset_tokens directly, keyed by (tenant_id, email), instead
 * of going through Password::getRepository().
 */
class PasswordController extends Controller
{
    private const TOKEN_TTL_MINUTES = 60;

    private static function rule(): PasswordRule
    {
        return PasswordRule::min(10)->letters()->numbers();
    }

    /** Invited -> Active: the user's first password set, via their invite link. */
    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', self::rule()],
        ]);

        $user = User::where('tenant_id', $data['tenant_id'])
            ->where('email', $data['email'])
            ->where('status', 'INVITED')
            ->first();

        if (! $user || ! $this->consumeValidToken($data['tenant_id'], $data['email'], $data['token'])) {
            throw ValidationException::withMessages(['token' => 'This invitation link is invalid or has expired.']);
        }

        $user->forceFill([
            'password' => $data['password'],
            'status' => 'ACTIVE',
        ])->save();

        return response()->json(['message' => 'Account activated. You can now log in.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'email' => ['required', 'email'],
        ]);

        $user = User::where('tenant_id', $data['tenant_id'])->where('email', $data['email'])->first();

        // Always respond the same way whether or not the account exists,
        // so this endpoint can't be used to enumerate registered emails.
        if ($user && $user->status->value !== 'SUSPENDED') {
            $plainToken = Str::random(64);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['tenant_id' => $data['tenant_id'], 'email' => $data['email']],
                ['token' => Hash::make($plainToken), 'created_at' => now()]
            );

            // TODO: dispatch the reset-email notification with $plainToken
            // once the notification/mail channel (FSD Section 5) is wired up.
        }

        return response()->json(['message' => 'If that account exists, a reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', self::rule()],
        ]);

        $user = User::where('tenant_id', $data['tenant_id'])->where('email', $data['email'])->first();

        if (! $user || ! $this->consumeValidToken($data['tenant_id'], $data['email'], $data['token'])) {
            throw ValidationException::withMessages(['token' => 'This reset link is invalid or has expired.']);
        }

        $user->forceFill(['password' => $data['password']])->save();
        $user->tokens()->delete(); // revoke all existing sessions on password change (FSD 3.4)

        return response()->json(['message' => 'Password has been reset. Please log in again.']);
    }

    /**
     * Single-use + time-limited: verifies the hash and the TTL window,
     * then deletes the row so it cannot be replayed, win or lose.
     */
    private function consumeValidToken(string $tenantId, string $email, string $plainToken): bool
    {
        $record = DB::table('password_reset_tokens')
            ->where('tenant_id', $tenantId)
            ->where('email', $email)
            ->first();

        if (! $record) {
            return false;
        }

        DB::table('password_reset_tokens')
            ->where('tenant_id', $tenantId)
            ->where('email', $email)
            ->delete();

        $notExpired = $record->created_at
            && now()->diffInMinutes($record->created_at) <= self::TOKEN_TTL_MINUTES;

        return $notExpired && Hash::check($plainToken, $record->token);
    }
}
