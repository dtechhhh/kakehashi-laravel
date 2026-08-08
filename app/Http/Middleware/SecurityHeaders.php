<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * W7-T4 — baseline security headers for every web response (SECURITY_CHECKLIST §2).
 * The Guest surface keeps its own stricter CSP via GuestSurface middleware.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // GuestSurface (route middleware) may already have set stricter values;
        // never overwrite an existing header (set() would append duplicates).
        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Strict-Transport-Security' => 'max-age=63072000; includeSubDomains',
        ];

        foreach ($defaults as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        // Strict CSP in production; dev keeps Vite HMR working without loosening prod.
        if (config('app.env') === 'production') {
            if (! $response->headers->has('Content-Security-Policy')) {
                $response->headers->set(
                    'Content-Security-Policy',
                    "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
                    ."img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; "
                    ."frame-src 'self' https://drive.google.com https://www.youtube.com; "
                    ."object-src 'none'; base-uri 'self'; form-action 'self'",
                );
            }
        }

        return $response;
    }
}
