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
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;

final class TwoFactorChallengeController
{
    public function __construct(
        private readonly TwoFactorAuthenticationProvider $totp,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $userId = $request->session()->get('login.id');
        $user = $userId === null ? null : User::query()->find($userId);

        if (empty($data['code']) && empty($data['recovery_code'])) {
            $this->recordFailure($user, 'missing_code', $request);

            return response()->json([
                'message' => 'TWOFA_FAILED',
                'errors' => ['code' => ['TWOFA_FAILED']],
            ], 422);
        }

        $throttleKey = 'two-factor:'.($userId ?? 'none').'|'.($request->ip() ?? 'unknown');

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $this->recordFailure($user, 'locked_out', $request);

            return response()->json([
                'message' => 'TWOFA_LOCKED_OUT',
                'retry_after' => RateLimiter::availableIn($throttleKey),
            ], 429);
        }

        if ($userId === null) {
            $this->recordFailure(null, 'challenge_expired', $request);

            return response()->json(['message' => 'TWOFA_CHALLENGE_EXPIRED'], 403);
        }

        if ($user === null || ! $user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->forget(['login.id', 'login.remember']);
            $this->recordFailure($user, 'challenge_expired', $request);

            return response()->json(['message' => 'TWOFA_CHALLENGE_EXPIRED'], 403);
        }

        if ($user->status_akun !== 'Aktif') {
            $request->session()->forget(['login.id', 'login.remember']);
            $this->recordFailure($user, 'inactive', $request);

            return response()->json(['message' => 'LOGIN_INACTIVE'], 403);
        }

        $remember = (bool) $request->session()->get('login.remember', false);

        if (! empty($data['recovery_code'])) {
            $consumed = $this->consumeRecoveryCode(
                (int) $userId,
                $data['recovery_code'],
                $request->ip(),
                $request->userAgent(),
            );

            if (! $consumed) {
                RateLimiter::hit($throttleKey, 900);
                $this->recordFailure($user, 'recovery_invalid', $request);

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
                $this->recordFailure($user, 'totp_invalid', $request);

                return response()->json([
                    'message' => 'TWOFA_FAILED',
                    'errors' => ['code' => ['TWOFA_FAILED']],
                ], 422);
            }

            DB::transaction(function () use ($request, $user): void {
                $this->recordSuccess(
                    $user,
                    ActionType::TWOFA_VERIFIED,
                    ['user_id' => $user->getKey()],
                    $request->ip(),
                    $request->userAgent(),
                );
            });
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
    private function consumeRecoveryCode(
        int $userId,
        string $recoveryCode,
        ?string $ip = null,
        ?string $userAgent = null,
    ): bool {
        return (bool) DB::transaction(function () use ($userId, $recoveryCode, $ip, $userAgent) {
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

            $remaining = array_values(array_filter(
                $user->recoveryCodes(),
                static fn (string $code): bool => ! hash_equals($code, $matched),
            ));
            $user->forceFill([
                'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(
                    json_encode($remaining, JSON_THROW_ON_ERROR),
                ),
            ])->save();
            $user->refresh();

            $this->recordSuccess(
                $user,
                ActionType::TWOFA_RECOVERY_USED,
                [
                    'user_id' => $user->getKey(),
                    'codes_left' => count($remaining),
                ],
                $ip,
                $userAgent,
            );

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function recordSuccess(
        User $user,
        ActionType $factorAction,
        array $detail,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $this->audit->record(
            actionType: $factorAction,
            entityType: 'user',
            entityId: $user->getKey(),
            detail: $detail,
            actorId: $user->getKey(),
            ip: $ip,
            userAgent: $userAgent,
        );

        $this->audit->record(
            actionType: ActionType::LOGIN_SUCCESS,
            entityType: 'user',
            entityId: $user->getKey(),
            detail: ['user_id' => $user->getKey()],
            actorId: $user->getKey(),
            ip: $ip,
            userAgent: $userAgent,
        );
    }

    private function recordFailure(?User $user, string $reason, Request $request): void
    {
        $this->audit->record(
            actionType: ActionType::TWOFA_FAILED,
            entityType: 'user',
            entityId: $user?->getKey(),
            detail: [
                'user_id' => $user?->getKey(),
                'reason' => $reason,
            ],
            actorId: $user?->getKey(),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }
}
