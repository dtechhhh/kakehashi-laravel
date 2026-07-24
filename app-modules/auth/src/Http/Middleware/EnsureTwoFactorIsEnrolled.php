<?php

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTwoFactorIsEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->requiresTwoFactorEnrollment()) {
            $allowed = $request->routeIs(
                'logout',
                'password.update',
                'two-factor.enable',
                'two-factor.confirm',
                'two-factor.qr-code',
                'two-factor.secret-key',
                'two-factor.recovery-codes',
                'two-factor.regenerate-recovery-codes',
            );

            if (! $allowed) {
                return response()->json([
                    'message' => 'TWOFA_ENROLL_REQUIRED',
                ], 403);
            }
        }

        return $next($request);
    }
}
