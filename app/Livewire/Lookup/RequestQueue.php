<?php

namespace App\Livewire\Lookup;

use App\Livewire\StepUpModal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Auth\Public\StepUpService;
use Modules\Auth\StepUpAction;
use Modules\LookupData\Public\LookupRequestService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S2 — request queue for lookup and company submissions (Super Admin).
 *
 * `lookup_request.status` / `company_request.status` are the decision
 * sources; this screen never creates or assumes `pending_request`.
 * Approve/reject are routine approvals gated by step-up
 * `MANAGE_LOOKUP_OR_COMPANY` scoped per request id. A double decision
 * surfaces as 409 `APV_DONE` and triggers a conflict banner + reload.
 */
final class RequestQueue extends Component
{
    use WithPagination;

    public string $tab = 'lookup_request';

    public string $status = 'pending';

    /**
     * @var array<int, string>
     */
    public array $rejectNotes = [];

    public ?string $actionError = null;

    public ?string $actionSuccess = null;

    public bool $conflict = false;

    /**
     * @var array{type: string, table: string, id: int, note?: string}|null
     */
    public ?array $pending = null;

    public function render()
    {
        Gate::authorize('lookup.request.decide');

        $requests = app(LookupRequestService::class)->paginateRequests(
            Auth::user(),
            $this->tab,
            ['status' => $this->status],
        );

        return view('livewire.lookup.request-queue', [
            'requests' => $requests,
            'requesterId' => (int) Auth::id(),
        ]);
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->status = 'pending';
        $this->resetPage();
        $this->actionError = null;
        $this->actionSuccess = null;
        $this->conflict = false;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->resetPage();
    }

    public function approve(int $id): void
    {
        $this->pending = ['type' => 'approve', 'table' => $this->tab, 'id' => $id];
        $this->requireStepUpOrExecute($this->tab, $id);
    }

    public function reject(int $id): void
    {
        $note = trim($this->rejectNotes[$id] ?? '');

        if ($note === '') {
            $this->actionSuccess = null;
            $this->actionError = __('ui.queue.errors.APV_NOTE');

            return;
        }

        $this->pending = ['type' => 'reject', 'table' => $this->tab, 'id' => $id, 'note' => $note];
        $this->requireStepUpOrExecute($this->tab, $id);
    }

    #[On('stepup.success')]
    public function handleStepUpSuccess(string $action, string $entityType, int $entityId): void
    {
        if ($this->pending === null || $action !== StepUpAction::MANAGE_LOOKUP_OR_COMPANY) {
            return;
        }

        if ($entityType !== $this->pending['table'] || $entityId !== $this->pending['id']) {
            return;
        }

        $this->executePending();
    }

    private function requireStepUpOrExecute(string $entityType, int $entityId): void
    {
        if (app(StepUpService::class)->hasValidElevation(StepUpAction::MANAGE_LOOKUP_OR_COMPANY, $entityType, $entityId)) {
            $this->executePending();

            return;
        }

        $this->dispatch('stepup.open',
            action: StepUpAction::MANAGE_LOOKUP_OR_COMPANY,
            entityType: $entityType,
            entityId: $entityId,
        )->to(StepUpModal::class);
    }

    private function executePending(): void
    {
        $pending = $this->pending;
        $service = app(LookupRequestService::class);
        $actor = Auth::user();

        try {
            match ([$pending['type'], $pending['table']]) {
                ['approve', 'lookup_request'] => $service->approveLookup($actor, $pending['id']),
                ['approve', 'company_request'] => $service->approveCompany($actor, $pending['id']),
                ['reject', 'lookup_request'] => $service->rejectLookup($actor, $pending['id'], $pending['note']),
                ['reject', 'company_request'] => $service->rejectCompany($actor, $pending['id'], $pending['note']),
                default => null,
            };

            $this->actionError = null;
            $this->conflict = false;
            $this->actionSuccess = __('ui.queue.success_decided');
            unset($this->rejectNotes[$pending['id']]);
        } catch (ConflictHttpException) {
            $this->actionSuccess = null;
            $this->actionError = null;
            $this->conflict = true;
        } catch (AccessDeniedHttpException|NotFoundHttpException) {
            $this->actionSuccess = null;
            $this->actionError = __('ui.queue.errors.APV_SELF');
        } catch (ValidationException $exception) {
            $this->actionSuccess = null;
            $this->actionError = $this->mapValidationError($exception);
        } catch (AuthorizationException $exception) {
            $this->actionSuccess = null;
            $this->actionError = __('ui.queue.errors.'.$exception->getMessage(), [], app()->getLocale());
        } finally {
            $this->pending = null;
        }
    }

    private function mapValidationError(ValidationException $exception): string
    {
        $code = collect($exception->errors())->flatten()->first();
        $translated = is_string($code) ? __('ui.queue.errors.'.$code, [], app()->getLocale()) : null;

        if (is_string($translated) && $translated !== 'ui.queue.errors.'.$code) {
            return $translated;
        }

        return is_string($code) ? $code : __('ui.queue.errors.GENERIC');
    }
}
