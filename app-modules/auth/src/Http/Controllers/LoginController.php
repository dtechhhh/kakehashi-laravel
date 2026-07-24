<?php

namespace Modules\Auth\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

final class LoginController
{
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = Str::lower(trim($credentials['email']));
        $throttleKey = $this->throttleKey($email, $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json([
                'message' => 'LOGIN_LOCKED_OUT',
                'retry_after' => RateLimiter::availableIn($throttleKey),
            ], 429);
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Auth::validate([
            'email' => $email,
            'password' => $credentials['password'],
        ])) {
            RateLimiter::hit($throttleKey, 900);

            return response()->json([
                'message' => 'LOGIN_FAILED',
                'errors' => ['email' => ['LOGIN_FAILED']],
            ], 422);
        }

        if ($user->status_akun !== 'Aktif') {
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
}
