<?php

namespace Modules\Auth\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

final class TwoFactorChallengeController
{
    public function __construct(
        private readonly TwoFactorAuthenticationProvider $totp,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        if (empty($data['code']) && empty($data['recovery_code'])) {
            return response()->json([
                'message' => 'TWOFA_FAILED',
                'errors' => ['code' => ['TWOFA_FAILED']],
            ], 422);
        }

        $userId = $request->session()->get('login.id');
        $throttleKey = 'two-factor:'.($userId ?? 'none').'|'.($request->ip() ?? 'unknown');

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json([
                'message' => 'TWOFA_LOCKED_OUT',
                'retry_after' => RateLimiter::availableIn($throttleKey),
            ], 429);
        }

        if ($userId === null) {
            return response()->json(['message' => 'TWOFA_CHALLENGE_EXPIRED'], 403);
        }

        $user = User::query()->find($userId);
        if ($user === null || ! $user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->forget(['login.id', 'login.remember']);

            return response()->json(['message' => 'TWOFA_CHALLENGE_EXPIRED'], 403);
        }

        if ($user->status_akun !== 'Aktif') {
            $request->session()->forget(['login.id', 'login.remember']);

            return response()->json(['message' => 'LOGIN_INACTIVE'], 403);
        }

        $remember = (bool) $request->session()->get('login.remember', false);

        if (! empty($data['recovery_code'])) {
            $consumed = $this->consumeRecoveryCode((int) $userId, $data['recovery_code']);

            if (! $consumed) {
                RateLimiter::hit($throttleKey, 900);

                return response()->json([
                    'message' => 'TWOFA_FAILED',
                    'errors' => ['recovery_code' => ['TWOFA_FAILED']],
                ], 422);
            }

            $user = User::query()->findOrFail($userId);
        } else {
            $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
            if (! $this->totp->verify($secret, $data['code'])) {
                RateLimiter::hit($throttleKey, 900);

                return response()->json([
                    'message' => 'TWOFA_FAILED',
                    'errors' => ['code' => ['TWOFA_FAILED']],
                ], 422);
            }
        }

        $request->session()->forget(['login.id', 'login.remember']);
        RateLimiter::clear($throttleKey);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return response()->json([
            'message' => 'LOGIN_SUCCESS',
            'must_change_password' => (bool) $user->must_change_password,
        ]);
    }

    /**
     * Consume a recovery code under a row lock so concurrent challenges
     * yield exactly one success for the same code.
     */
    private function consumeRecoveryCode(int $userId, string $recoveryCode): bool
    {
        return (bool) DB::transaction(function () use ($userId, $recoveryCode) {
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();

            if ($user === null || ! $user->hasEnabledTwoFactorAuthentication()) {
                return false;
            }

            $matched = collect($user->recoveryCodes())->first(
                fn (string $code) => hash_equals($code, $recoveryCode)
            );

            if ($matched === null) {
                return false;
            }

            $user->replaceRecoveryCode($matched);

            return true;
        });
    }
}
