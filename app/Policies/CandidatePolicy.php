<?php

namespace App\Policies;

use App\Models\User;
use Modules\Auth\Rbac;

/**
 * MODULE_CANDIDATES §5 — Staf Input buat/edit draft; Approver/Super Admin view saja di path ini.
 * Soft-delete/restore sengaja tidak diekspos.
 */
final class CandidatePolicy
{
    public function create(User $actor): bool
    {
        return $this->isActiveStaffInput($actor)
            && $actor->hasPermissionTo('candidate.create');
    }

    public function update(User $actor): bool
    {
        return $this->create($actor);
    }

    public function submit(User $actor): bool
    {
        return $this->isActiveStaffInput($actor)
            && $actor->hasPermissionTo('candidate.submit');
    }

    public function view(User $actor): bool
    {
        return $actor->status_akun === 'Aktif'
            && $actor->hasPermissionTo('candidate.view');
    }

    private function isActiveStaffInput(User $actor): bool
    {
        return $actor->status_akun === 'Aktif'
            && $actor->hasRole(Rbac::STAFF_INPUT);
    }
}
