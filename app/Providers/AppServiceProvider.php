<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\CandidatePolicy;
use App\Policies\LookupPolicy;
use App\Policies\PendingRequestPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Shared\Approval\PendingRequest;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(PendingRequest::class, PendingRequestPolicy::class);
        Gate::define('lookup.manage', [LookupPolicy::class, 'manage']);
        Gate::define('lookup.request.submit', [LookupPolicy::class, 'requestLookup']);
        Gate::define('company.request.submit', [LookupPolicy::class, 'requestCompany']);
        Gate::define('lookup.request.decide', [LookupPolicy::class, 'decideLookup']);
        Gate::define('company.request.decide', [LookupPolicy::class, 'decideCompany']);
        Gate::define('company.manage', [LookupPolicy::class, 'decideCompany']);
        Gate::define('candidate.create', [CandidatePolicy::class, 'create']);
        Gate::define('candidate.update', [CandidatePolicy::class, 'update']);
        Gate::define('candidate.submit', [CandidatePolicy::class, 'submit']);
        Gate::define('candidate.view', [CandidatePolicy::class, 'view']);
    }
}
