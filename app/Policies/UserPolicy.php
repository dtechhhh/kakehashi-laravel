<?php

namespace App\Policies;

use App\Models\User;
use Modules\Auth\Rbac;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isSuperAdmin($actor);
    }

    public function view(User $actor, User $target): bool
    {
        return $this->isSuperAdmin($actor);
    }

    public function create(User $actor): bool
    {
        return $this->isSuperAdmin($actor);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->isSuperAdmin($actor);
    }

    public function assignRoles(User $actor, User $target): bool
    {
        return $this->isSuperAdmin($actor) && ! $actor->is($target);
    }

    public function deactivate(User $actor, User $target): bool
    {
        return $this->isSuperAdmin($actor) && ! $actor->is($target);
    }

    public function reactivate(User $actor, User $target): bool
    {
        return $this->isSuperAdmin($actor);
    }

    public function resetPassword(User $actor, User $target): bool
    {
        return $this->isSuperAdmin($actor) && ! $actor->is($target);
    }

    private function isSuperAdmin(User $actor): bool
    {
        return $actor->status_akun === 'Aktif' && $actor->hasRole(Rbac::SUPER_ADMIN);
    }
}
