<?php

namespace App\Policies;

use App\Models\User;
use Modules\Auth\Rbac;

final class LookupPolicy
{
    public function manage(User $actor): bool
    {
        return $actor->status_akun === 'Aktif'
            && $actor->hasRole(Rbac::SUPER_ADMIN)
            && $actor->hasPermissionTo('lookup.manage');
    }

    public function requestLookup(User $actor): bool
    {
        return $actor->status_akun === 'Aktif'
            && $actor->hasAnyRole([Rbac::STAFF_INPUT, Rbac::ASSISTANT_MANAGER])
            && $actor->hasPermissionTo('lookup.request');
    }

    public function requestCompany(User $actor): bool
    {
        return $actor->status_akun === 'Aktif'
            && $actor->hasRole(Rbac::ASSISTANT_MANAGER)
            && $actor->hasPermissionTo('company.request');
    }

    public function decideLookup(User $actor): bool
    {
        return $this->manage($actor);
    }

    public function decideCompany(User $actor): bool
    {
        return $actor->status_akun === 'Aktif'
            && $actor->hasRole(Rbac::SUPER_ADMIN)
            && $actor->hasPermissionTo('company.manage');
    }
}
