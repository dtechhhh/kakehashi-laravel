<?php

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePasswordIsCurrent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->must_change_password) {
            $allowed = $request->routeIs('language.switch', 'password.update', 'password.forced', 'logout');

            if (! $allowed) {
                return response()->json([
                    'message' => 'MUST_CHANGE_PASSWORD',
                ], 403);
            }
        }

        return $next($request);
    }
}
