<?php

namespace Modules\Auth\Public;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Rbac;
use Spatie\Permission\Models\Role;

class UserRbacService
{
    /**
     * @param  list<string>  $roles
     */
    public function assignRoles(User $actor, User $target, array $roles): User
    {
        $roles = array_values(array_unique($roles));

        return DB::transaction(function () use ($actor, $roles, $target): User {
            $this->lockRbacWrites();
            [$actor, $target] = $this->lockUsers($actor, $target);

            $this->assertSuperAdmin($actor);

            if ($actor->is($target)) {
                $this->fail('roles', 'USR_SELF_ROLE');
            }

            $this->assertKnownRoles($roles);

            if ($code = Rbac::separationOfDutiesViolation($roles)) {
                $this->fail('roles', $code);
            }

            $this->assertSuperAdminRemains($target, in_array(Rbac::SUPER_ADMIN, $roles, true));

            $target->syncRoles($roles);

            return $target->refresh();
        });
    }

    public function deactivateUser(User $actor, User $target): User
    {
        return DB::transaction(function () use ($actor, $target): User {
            $this->lockRbacWrites();
            [$actor, $target] = $this->lockUsers($actor, $target);

            $this->assertSuperAdmin($actor);

            if ($actor->is($target)) {
                $this->fail('status_akun', 'USR_SELF_DEACTIVATE');
            }

            $this->assertSuperAdminRemains($target, false);

            $target->forceFill([
                'status_akun' => 'Nonaktif',
                'deactivated_at' => now(),
                'deactivated_by' => $actor->getKey(),
            ])->save();

            return $target->refresh();
        });
    }

    public function reactivateUser(User $actor, User $target): User
    {
        return DB::transaction(function () use ($actor, $target): User {
            [$actor, $target] = $this->lockUsers($actor, $target);

            $this->assertSuperAdmin($actor);

            $target->forceFill([
                'status_akun' => 'Aktif',
                'deactivated_at' => null,
                'deactivated_by' => null,
            ])->save();

            return $target->refresh();
        });
    }

    public function resetPasswordByAdmin(User $actor, User $target, string $temporaryPassword): User
    {
        return DB::transaction(function () use ($actor, $target, $temporaryPassword): User {
            [$actor, $target] = $this->lockUsers($actor, $target);

            $this->assertSuperAdmin($actor);

            if ($actor->is($target)) {
                $this->fail('password', 'USR_SELF_RESET');
            }

            $target->forceFill([
                'password' => Hash::make($temporaryPassword),
                'must_change_password' => true,
            ])->save();

            return $target->refresh();
        });
    }

    private function assertSuperAdmin(User $actor): void
    {
        if ($actor->status_akun !== 'Aktif' || ! $actor->hasRole(Rbac::SUPER_ADMIN)) {
            throw new AuthorizationException('USR_ADMIN_ONLY');
        }
    }

    /**
     * @param  list<string>  $roles
     */
    private function assertKnownRoles(array $roles): void
    {
        if ($roles === [] || array_diff($roles, Rbac::ROLES) !== []) {
            $this->fail('roles', 'USR_ROLE_UNKNOWN');
        }
    }

    /**
     * @return array{User, User}
     */
    private function lockUsers(User $actor, User $target): array
    {
        $users = [];
        $ids = array_unique([$actor->getKey(), $target->getKey()]);
        sort($ids);

        foreach ($ids as $id) {
            $users[$id] = User::query()->lockForUpdate()->findOrFail($id);
        }

        return [$users[$actor->getKey()], $users[$target->getKey()]];
    }

    private function lockRbacWrites(): void
    {
        // ponytail: one role-row lock is enough for the MVP's low-volume account administration.
        Role::query()
            ->where('name', Rbac::SUPER_ADMIN)
            ->where('guard_name', 'web')
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertSuperAdminRemains(User $target, bool $targetRemainsSuperAdmin): void
    {
        if ($targetRemainsSuperAdmin || $target->status_akun !== 'Aktif' || ! $target->hasRole(Rbac::SUPER_ADMIN)) {
            return;
        }

        $activeSuperAdminIds = User::query()
            ->where('status_akun', 'Aktif')
            ->whereHas('roles', fn ($query) => $query->where('name', Rbac::SUPER_ADMIN))
            ->lockForUpdate()
            ->pluck('id');

        if ($activeSuperAdminIds->count() === 1 && $activeSuperAdminIds->contains($target->getKey())) {
            $this->fail('roles', 'USR_LAST_SUPERADMIN');
        }
    }

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code]);
    }
}
