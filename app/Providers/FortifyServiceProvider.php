<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        // Challenge throttle is enforced manually in TwoFactorChallengeController
        // (Fortify routes are ignored; named limiter would be dead code).
    }
}
