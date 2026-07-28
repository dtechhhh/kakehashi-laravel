<?php

namespace Modules\LookupData\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\LookupData\Public\LookupService;

class LookupDataServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LookupService::class);
    }

    public function boot(): void {}
}
