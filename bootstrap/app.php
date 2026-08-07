<?php

use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\GuestSurface;
use App\Http\Middleware\Localization;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Auth\Http\Middleware\EnsureAccountIsActive;
use Modules\Auth\Http\Middleware\EnsurePasswordIsCurrent;
use Modules\Auth\Http\Middleware\EnsureTwoFactorIsEnrolled;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'guest.surface' => GuestSurface::class,
        ]);

        $middleware->appendToGroup('web', [
            Localization::class,
            EnsureAccountIsActive::class,
            EnsurePasswordIsCurrent::class,
            EnsureTwoFactorIsEnrolled::class,
            SecurityHeaders::class,
            ForceHttps::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
