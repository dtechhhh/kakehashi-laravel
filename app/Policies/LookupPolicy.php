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
}
