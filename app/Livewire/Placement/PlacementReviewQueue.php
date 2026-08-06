<?php

namespace App\Livewire\Placement;

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
use Modules\Placement\Public\PlacementQueryService;
use Modules\Placement\Services\PlacementBatchService;
use Modules\Placement\Services\PlacementContainerService;
use Modules\Placement\Services\PlacementForceMajeurService;
use Modules\Placement\Services\PlacementParticipationService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * UI-W5-T3 — placement Checker approval queue (Manajer Job, no step-up).
 *
 * PC_CREATE approve → Aktif, reject + note → Draft (code retained);
 * PC_CANCEL_ACTIVE approve → Dibatalkan, reject + note → tetap Aktif.
 * Double decision and stale version surface as a 409 conflict banner.
 */
final class PlacementReviewQueue extends Component
{
    use WithPagination;

    public ?int $rejectingId = null;

    public string $rejectNote = '';

    public ?string $actionError = null;

    public bool $conflict = false;

    public ?int $expelApprovingId = null;

    public string $expelApproveNote = '';

    /**
     * @var array{pendingId: int, participantId: int, participantVersion: int, note: string}|null
     */
    public ?array $expelPending = null;

    public function render()
    {
        Gate::authorize('placement.review');

        return view('livewire.placement.placement-review-queue', [
            'queue' => app(PlacementQueryService::class)->reviewQueue(Auth::user()),
        ]);
    }

    public function approve(int $pendingRequestId, string $type, int $containerVersion, ?int $participantVersion = null): void
    {
        $this->resetActionState();

        try {
            $this->decide($pendingRequestId, $type, $containerVersion, $participantVersion, approve: true);

            session()->flash('status', __('ui.placement.queue.approved'));
            $this->redirect(route('placements.review'));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (AuthorizationException|AccessDeniedHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        }
    }

    public function startReject(int $pendingRequestId): void
    {
        $this->rejectingId = $pendingRequestId;
        $this->rejectNote = '';
        $this->actionError = null;
        $this->conflict = false;
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
        $this->rejectNote = '';
    }

    public function reject(int $pendingRequestId, string $type, int $containerVersion, ?int $participantVersion = null): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if (trim($this->rejectNote) === '') {
            $this->actionError = __('ui.placement.queue.note_required');

            return;
        }

        try {
            $this->decide($pendingRequestId, $type, $containerVersion, $participantVersion, approve: false);

            session()->flash('status', __('ui.placement.queue.rejected'));
            $this->redirect(route('placements.review'));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (AuthorizationException|AccessDeniedHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        }
    }

    // ----- P6 expel approve (step-up wajib) -----

    public function startExpelApprove(int $pendingRequestId): void
    {
        $this->expelApprovingId = $pendingRequestId;
        $this->expelApproveNote = '';
        $this->actionError = null;
        $this->conflict = false;
    }

    public function cancelExpelApprove(): void
    {
        $this->expelApprovingId = null;
        $this->expelApproveNote = '';
    }

    public function approveExpel(int $pendingRequestId, int $participantId, int $participantVersion): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if (trim($this->expelApproveNote) === '') {
            $this->actionError = __('ui.placement.status.note_required');

            return;
        }

        $this->expelPending = [
            'pendingId' => $pendingRequestId,
            'participantId' => $participantId,
            'participantVersion' => $participantVersion,
            'note' => trim($this->expelApproveNote),
        ];

        $this->requireExpelStepUpOrExecute();
    }

    #[On('stepup.success')]
    public function handleStepUpSuccess(string $action, string $entityType, int $entityId): void
    {
        if ($this->expelPending !== null
            && $action === StepUpAction::APPROVE_CANDIDATE_EXPEL
            && $entityType === 'placement_participants'
            && $entityId === $this->expelPending['participantId']
        ) {
            $this->executeExpelPending();
        }
    }

    private function requireExpelStepUpOrExecute(): void
    {
        if (app(StepUpService::class)->hasValidElevation(
            StepUpAction::APPROVE_CANDIDATE_EXPEL,
            'placement_participants',
            $this->expelPending['participantId'],
        )) {
            $this->executeExpelPending();

            return;
        }

        $this->dispatch('stepup.open',
            action: StepUpAction::APPROVE_CANDIDATE_EXPEL,
            entityType: 'placement_participants',
            entityId: $this->expelPending['participantId'],
        )->to(StepUpModal::class);
    }

    private function executeExpelPending(): void
    {
        $pending = $this->expelPending;

        try {
            app(PlacementParticipationService::class)
                ->approveExpel(Auth::user(), $pending['pendingId'], $pending['note'], ['version' => $pending['participantVersion']]);

            session()->flash('status', __('ui.placement.status.expel_approved'));
            $this->redirect(route('placements.review'));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (AuthorizationException|AccessDeniedHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        } finally {
            $this->expelPending = null;
        }
    }

    private function firstError(ValidationException $exception): string
    {
        $message = (string) collect($exception->errors())->flatten()->first();
        $key = 'ui.placement.errors.'.$message;
        $translated = __($key);

        return $translated === $key ? $message : $translated;
    }

    private function translateCode(string $code): string
    {
        $key = 'ui.placement.errors.'.$code;
        $translated = __($key);

        return $translated === $key ? $code : $translated;
    }

    private function resetActionState(): void
    {
        $this->actionError = null;
        $this->conflict = false;
    }

    private function decide(int $pendingRequestId, string $type, int $containerVersion, ?int $participantVersion, bool $approve): void
    {
        $container = app(PlacementContainerService::class);
        $note = $approve ? null : trim($this->rejectNote);

        match ($type) {
            'PC_CREATE' => $approve
                ? $container->approve(Auth::user(), $pendingRequestId, ['version' => $containerVersion])
                : $container->reject(Auth::user(), $pendingRequestId, $note, ['version' => $containerVersion]),
            'PC_CANCEL_ACTIVE' => $approve
                ? $container->approveCancelActive(Auth::user(), $pendingRequestId, ['version' => $containerVersion])
                : $container->rejectCancelActive(Auth::user(), $pendingRequestId, $note, ['version' => $containerVersion]),
            'PLACEMENT_BATCH' => $approve
                ? app(PlacementBatchService::class)->approveBatch(Auth::user(), $pendingRequestId, ['version' => $containerVersion])
                : app(PlacementBatchService::class)->rejectBatch(Auth::user(), $pendingRequestId, $note, ['version' => $containerVersion]),
            'FORCE_MAJEUR' => $approve
                ? app(PlacementForceMajeurService::class)->approveForceMajeur(Auth::user(), $pendingRequestId, ['version' => $containerVersion])
                : app(PlacementForceMajeurService::class)->rejectForceMajeur(Auth::user(), $pendingRequestId, $note, ['version' => $containerVersion]),
            'PLACEMENT_RESIGN' => $approve
                ? app(PlacementParticipationService::class)->approveResign(Auth::user(), $pendingRequestId, null, ['version' => $participantVersion])
                : app(PlacementParticipationService::class)->rejectResign(Auth::user(), $pendingRequestId, $note, ['version' => $participantVersion]),
            'PLACEMENT_EXPEL' => $approve
                ? throw new \InvalidArgumentException('Expel approve requires step-up.')
                : app(PlacementParticipationService::class)->rejectExpel(Auth::user(), $pendingRequestId, $note, ['version' => $participantVersion]),
            default => throw new \InvalidArgumentException('Unsupported pending type.'),
        };
    }
}
