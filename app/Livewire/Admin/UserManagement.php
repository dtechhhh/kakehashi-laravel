<?php

namespace App\Livewire\Admin;

use App\Livewire\StepUpModal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Auth\Public\StepUpService;
use Modules\Auth\Public\UserRbacService;
use Modules\Auth\Rbac;
use Modules\Auth\StepUpAction;

/**
 * S4 — account management (partial by design).
 *
 * Covers only capabilities with an existing Wave 1 contract: list users,
 * assign/unassign roles (step-up), deactivate (step-up), reactivate (no
 * step-up), admin password reset (no step-up). User creation has no public
 * service contract yet and is deferred; the screen never simulates it.
 */
final class UserManagement extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $editingRolesFor = null;

    /**
     * @var array<int, list<string>>
     */
    public array $roleDrafts = [];

    public ?string $actionError = null;

    public ?string $actionSuccess = null;

    /**
     * Staged mutation waiting for step-up success.
     *
     * @var array{type: string, userId: int, roles?: list<string>}|null
     */
    public ?array $pending = null;

    public ?int $resettingPasswordFor = null;

    public string $temporaryPassword = '';

    private function service(): UserRbacService
    {
        return app(UserRbacService::class);
    }

    public function render()
    {
        Gate::authorize('viewAny', User::class);

        $users = $this->service()->paginate(auth()->user(), trim($this->search));

        return view('livewire.admin.user-management', [
            'users' => $users,
            'roles' => Rbac::INTERNAL_ROLES,
        ]);
    }

    public function startEditRoles(int $userId): void
    {
        try {
            $user = $this->service()->findForAdmin(auth()->user(), $userId);
            $this->roleDrafts[$userId] = $user->getRoleNames()->all();
            $this->editingRolesFor = $userId;
            $this->actionError = null;
            $this->actionSuccess = null;
        } catch (AuthorizationException $exception) {
            $this->actionSuccess = null;
            $this->actionError = $this->mapError($exception);
        }
    }

    public function cancelEditRoles(): void
    {
        $this->editingRolesFor = null;
        $this->roleDrafts = [];
    }

    public function saveRoles(int $userId): void
    {
        $this->pending = [
            'type' => 'roles',
            'userId' => $userId,
            'roles' => array_values(array_unique($this->roleDrafts[$userId] ?? [])),
        ];

        if (app(StepUpService::class)->hasValidElevation(StepUpAction::USER_ROLE_OR_DEACTIVATE, 'user', $userId)) {
            $this->executePending();

            return;
        }

        $this->dispatch('stepup.open',
            action: StepUpAction::USER_ROLE_OR_DEACTIVATE,
            entityType: 'user',
            entityId: $userId,
        )->to(StepUpModal::class);
    }

    public function deactivate(int $userId): void
    {
        $this->pending = ['type' => 'deactivate', 'userId' => $userId];

        if (app(StepUpService::class)->hasValidElevation(StepUpAction::USER_ROLE_OR_DEACTIVATE, 'user', $userId)) {
            $this->executePending();

            return;
        }

        $this->dispatch('stepup.open',
            action: StepUpAction::USER_ROLE_OR_DEACTIVATE,
            entityType: 'user',
            entityId: $userId,
        )->to(StepUpModal::class);
    }

    public function reactivate(int $userId): void
    {
        $this->pending = ['type' => 'reactivate', 'userId' => $userId];
        $this->executePending();
    }

    public function resetPassword(int $userId): void
    {
        $this->resettingPasswordFor = $userId;
        $this->temporaryPassword = '';
        $this->actionError = null;
        $this->actionSuccess = null;
    }

    public function confirmResetPassword(int $userId): void
    {
        $this->pending = ['type' => 'resetPassword', 'userId' => $userId];
        $this->executePending();
        $this->resettingPasswordFor = null;
        $this->temporaryPassword = '';
    }

    #[On('stepup.success')]
    public function handleStepUpSuccess(string $action, string $entityType, int $entityId): void
    {
        if ($this->pending === null
            || $action !== StepUpAction::USER_ROLE_OR_DEACTIVATE
            || $entityType !== 'user'
            || $entityId !== $this->pending['userId']) {
            return;
        }

        $this->executePending();
    }

    private function executePending(): void
    {
        $actor = auth()->user();
        $pending = $this->pending;

        try {
            $target = $this->service()->findForAdmin($actor, $pending['userId']);

            match ($pending['type']) {
                'roles' => $this->service()->assignRoles($actor, $target, $pending['roles']),
                'deactivate' => $this->service()->deactivateUser($actor, $target),
                'reactivate' => $this->service()->reactivateUser($actor, $target),
                'resetPassword' => $this->service()->resetPasswordByAdmin($actor, $target, $this->temporaryPassword),
                default => null,
            };

            $this->actionError = null;
            $this->actionSuccess = __('ui.admin.success_generic');
            $this->editingRolesFor = null;
            $this->roleDrafts = [];
        } catch (ValidationException|AuthorizationException $exception) {
            $this->actionSuccess = null;
            $this->actionError = $this->mapError($exception);
        } finally {
            $this->pending = null;
        }
    }

    private function mapError(ValidationException|AuthorizationException $exception): string
    {
        if ($exception instanceof AuthorizationException) {
            return __('ui.admin.errors.'.$exception->getMessage(), [], app()->getLocale());
        }

        $code = collect($exception->errors())->flatten()->first();

        return $code !== null
            ? __('ui.admin.errors.'.$code, [], app()->getLocale())
            : __('ui.admin.error_generic');
    }
}
