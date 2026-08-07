<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * W6-T6 — security headers + cache policy + JP-only locale for the Guest
 * surface (MODULE_GUEST_ACCESS §7/§8). Plain Blade pages only (no Livewire),
 * which keeps the CSP strict without nonce plumbing.
 */
final class GuestSurface
{
    public function handle(Request $request, Closure $next): Response
    {
        // Tamu = Japanese-only surface (PRD §9.4).
        app()->setLocale('ja');

        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
            ."img-src 'self' data: https:; media-src 'self' https:; "
            ."frame-src 'self' https://www.youtube.com https://drive.google.com; "
            ."object-src 'none'; base-uri 'self'; form-action 'self'; connect-src 'self'",
        );

        return $response;
    }
}
