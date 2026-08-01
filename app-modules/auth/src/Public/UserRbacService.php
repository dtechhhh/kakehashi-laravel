<?php

namespace Modules\Auth\Public;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Rbac;
use Modules\Auth\Rules\PasswordPolicy;
use Modules\Auth\StepUpAction;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;
use Spatie\Permission\Models\Role;

class UserRbacService
{
    public function __construct(
        private readonly StepUpService $stepUp,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $roles
     */
    public function assignRoles(User $actor, User $target, array $roles): User
    {
        $roles = array_values(array_unique($roles));

        return DB::transaction(function () use ($actor, $roles, $target): User {
            $this->lockRbacWrites();
            [$actor, $target] = $this->lockUsers($actor, $target);

            $this->assertAuthenticatedActor($actor);
            $this->assertSuperAdmin($actor);

            if ($actor->is($target)) {
                $this->fail('roles', 'USR_SELF_ROLE');
            }

            $this->assertKnownRoles($roles);

            if ($code = Rbac::separationOfDutiesViolation($roles)) {
                $this->fail('roles', $code);
            }

            $this->assertSuperAdminRemains($target, in_array(Rbac::SUPER_ADMIN, $roles, true));

            $oldRoles = $target->getRoleNames()->sort()->values()->all();
            $newRoles = $roles;
            sort($newRoles);

            if ($oldRoles === $newRoles) {
                return $target->refresh();
            }

            $this->stepUp->require(
                StepUpAction::USER_ROLE_OR_DEACTIVATE,
                'user',
                $target->getKey(),
            );

            $target->syncRoles($roles);

            $this->audit->record(
                actionType: $oldRoles === [] ? ActionType::ROLE_ASSIGNED : ActionType::ROLE_CHANGED,
                entityType: 'user',
                entityId: $target->getKey(),
                detail: [
                    'target_user_id' => $target->getKey(),
                    'old_role' => implode(', ', $oldRoles),
                    'new_role' => implode(', ', $newRoles),
                ],
                actorId: $actor->getKey(),
            );

            return $target->refresh();
        });
    }

    public function deactivateUser(User $actor, User $target): User
    {
        return DB::transaction(function () use ($actor, $target): User {
            $this->lockRbacWrites();
            [$actor, $target] = $this->lockUsers($actor, $target);

            $this->assertAuthenticatedActor($actor);
            $this->assertSuperAdmin($actor);

            if ($actor->is($target)) {
                $this->fail('status_akun', 'USR_SELF_DEACTIVATE');
            }

            $this->assertSuperAdminRemains($target, false);

            $this->stepUp->require(
                StepUpAction::USER_ROLE_OR_DEACTIVATE,
                'user',
                $target->getKey(),
            );

            $target->forceFill([
                'status_akun' => 'Nonaktif',
                'deactivated_at' => now(),
                'deactivated_by' => $actor->getKey(),
            ])->save();

            $this->audit->record(
                actionType: ActionType::USER_DEACTIVATED,
                entityType: 'user',
                entityId: $target->getKey(),
                detail: ['target_user_id' => $target->getKey()],
                actorId: $actor->getKey(),
            );

            return $target->refresh();
        });
    }

    public function reactivateUser(User $actor, User $target): User
    {
        return DB::transaction(function () use ($actor, $target): User {
            [$actor, $target] = $this->lockUsers($actor, $target);

            $this->assertAuthenticatedActor($actor);
            $this->assertSuperAdmin($actor);

            $target->forceFill([
                'status_akun' => 'Aktif',
                'deactivated_at' => null,
                'deactivated_by' => null,
            ])->save();

            $this->audit->record(
                actionType: ActionType::USER_REACTIVATED,
                entityType: 'user',
                entityId: $target->getKey(),
                detail: [
                    'target_user_id' => $target->getKey(),
                    'changed' => ['status_akun' => ['Nonaktif', 'Aktif']],
                ],
                actorId: $actor->getKey(),
            );

            return $target->refresh();
        });
    }

    public function resetPasswordByAdmin(User $actor, User $target, string $temporaryPassword): User
    {
        return DB::transaction(function () use ($actor, $target, $temporaryPassword): User {
            [$actor, $target] = $this->lockUsers($actor, $target);

            $this->assertAuthenticatedActor($actor);
            $this->assertSuperAdmin($actor);

            if ($actor->is($target)) {
                $this->fail('password', 'USR_SELF_RESET');
            }

            Validator::make(
                ['password' => $temporaryPassword],
                ['password' => ['required', 'string', new PasswordPolicy]],
            )->validate();

            $target->forceFill([
                'password' => $temporaryPassword,
                'must_change_password' => true,
            ])->save();

            $this->audit->record(
                actionType: ActionType::PASSWORD_RESET_BY_ADMIN,
                entityType: 'user',
                entityId: $target->getKey(),
                detail: ['target_user_id' => $target->getKey()],
                actorId: $actor->getKey(),
            );

            return $target->refresh();
        });
    }

    /**
     * Read-only list of users for the S4 account management screen.
     *
     * Authorized read: authenticated active Super Admin only. Results are
     * paginated and include current roles; search matches name or email.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(User $actor, string $search = '', int $perPage = 25): LengthAwarePaginator
    {
        $this->assertAuthenticatedActor($actor);
        $this->assertSuperAdmin($actor);

        return User::query()
            ->with('roles')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'ilike', '%'.$search.'%')
                        ->orWhere('email', 'ilike', '%'.$search.'%');
                });
            })
            ->orderBy('id')
            ->paginate(max(1, min(100, $perPage)));
    }

    private function assertAuthenticatedActor(User $actor): void
    {
        if ((int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException('USR_ADMIN_ONLY');
        }
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
