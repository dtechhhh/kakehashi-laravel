<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Presentation-only locale switching (id/ja). Default internal language is ID.
 * Domain logic never depends on the request locale.
 */
final class Localization
{
    private const ALLOWED = ['id', 'ja'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = session('locale');

        if (in_array($locale, self::ALLOWED, true)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
