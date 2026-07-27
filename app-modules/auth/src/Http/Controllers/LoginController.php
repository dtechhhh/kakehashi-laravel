<?php

namespace Modules\Auth\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;

final class LoginController
{
    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = Str::lower(trim($credentials['email']));
        $throttleKey = $this->throttleKey($email, $request->ip());
        $user = User::query()->where('email', $email)->first();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $retryAfter = RateLimiter::availableIn($throttleKey);

            $this->recordFailure(
                $audit,
                ActionType::LOGIN_LOCKED_OUT,
                $user,
                $email,
                'locked_out',
                0,
                $request,
                now()->addSeconds($retryAfter)->toIso8601String(),
            );

            return response()->json([
                'message' => 'LOGIN_LOCKED_OUT',
                'retry_after' => $retryAfter,
            ], 429);
        }

        if ($user === null || ! Auth::validate([
            'email' => $email,
            'password' => $credentials['password'],
        ])) {
            RateLimiter::hit($throttleKey, 900);

            $this->recordFailure(
                $audit,
                ActionType::LOGIN_FAILED,
                $user,
                $email,
                'bad_credentials',
                max(0, 5 - RateLimiter::attempts($throttleKey)),
                $request,
            );

            return response()->json([
                'message' => 'LOGIN_FAILED',
                'errors' => ['email' => ['LOGIN_FAILED']],
            ], 422);
        }

        if ($user->status_akun !== 'Aktif') {
            $this->recordFailure(
                $audit,
                ActionType::LOGIN_FAILED,
                $user,
                $email,
                'inactive',
                max(0, 5 - RateLimiter::attempts($throttleKey)),
                $request,
            );

            return response()->json([
                'message' => 'LOGIN_INACTIVE',
            ], 403);
        }

        RateLimiter::clear($throttleKey);

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put([
                'login.id' => $user->getAuthIdentifier(),
                'login.remember' => false,
            ]);

            return response()->json([
                'message' => 'TWOFA_REQUIRED',
            ]);
        }

        $audit->record(
            actionType: ActionType::LOGIN_SUCCESS,
            entityType: 'user',
            entityId: $user->getKey(),
            detail: ['user_id' => $user->getKey()],
            actorId: $user->getKey(),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        Auth::login($user, false);
        $request->session()->regenerate();

        return response()->json([
            'message' => 'LOGIN_SUCCESS',
            'must_change_password' => (bool) $user->must_change_password,
        ]);
    }

    private function throttleKey(string $email, ?string $ip): string
    {
        return 'login:'.$email.'|'.($ip ?? 'unknown');
    }

    private function recordFailure(
        AuditLogger $audit,
        ActionType $action,
        ?User $user,
        string $email,
        string $reason,
        int $attemptsLeft,
        Request $request,
        ?string $lockedUntil = null,
    ): void {
        $detail = [
            'user_id' => $user?->getKey(),
            'reason' => $reason,
            'attempts_left' => $attemptsLeft,
        ];

        if ($user === null) {
            $detail['email_masked_or_fingerprint'] = $audit->fingerprintEmail($email);
        }

        if ($lockedUntil !== null) {
            $detail['locked_until'] = $lockedUntil;
        }

        $audit->record(
            actionType: $action,
            entityType: 'auth',
            entityId: $user?->getKey(),
            detail: $detail,
            actorId: null,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }
}
