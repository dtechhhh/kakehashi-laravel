<?php

namespace App\Livewire\Placement;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Placement\Public\PlacementQueryService;
use Modules\Placement\Services\PlacementContainerService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * UI-W5-T2 — P3 placement-container draft form (Maker).
 *
 * Save Draft produces no code/pending. Submit goes through the existing
 * PlacementContainerService (P-YYYY-NNNNN code + PC_CREATE pending in one
 * transaction). Cancel is allowed for Draft and Menunggu Approval only.
 * Optimistic `version` is sent with every write; a stale version surfaces as
 * a 409 conflict banner with reload.
 */
final class PlacementForm extends Component
{
    public ?int $containerId = null;

    public int $version = 0;

    public string $status = '';

    public bool $isEditing = false;

    public bool $readonly = false;

    public bool $canCancel = false;

    public bool $conflict = false;

    public string $nama = '';

    public string $perusahaanId = '';

    public array $serverErrors = [];

    public ?string $actionError = null;

    public function mount(?int $containerId = null): void
    {
        if ($containerId === null) {
            return;
        }

        $this->containerId = $containerId;
        $this->isEditing = true;

        $container = app(PlacementContainerService::class)->findOrFail($containerId);
        $this->status = (string) $container->status;
        $this->version = (int) $container->version;
        $this->nama = (string) $container->nama;
        $this->perusahaanId = (string) $container->perusahaan_id;

        if ($container->status !== 'Draft') {
            $this->readonly = true;
        }

        $this->canCancel = in_array($container->status, ['Draft', 'Menunggu Approval'], true);
    }

    public function render()
    {
        Gate::authorize('placement.execute');

        return view('livewire.placement.placement-form', [
            'perusahaanOptions' => app(PlacementQueryService::class)->perusahaanOptions(
                Auth::user(),
                $this->perusahaanId !== '' ? (int) $this->perusahaanId : null,
            ),
        ]);
    }

    public function saveDraft(): void
    {
        $this->clearActionState();

        try {
            $service = app(PlacementContainerService::class);
            $container = $this->isEditing
                ? $service->updateDraft(Auth::user(), (int) $this->containerId, $this->payload() + ['version' => $this->version])
                : $service->createDraft(Auth::user(), $this->payload());

            $this->containerId = (int) $container->id;
            $this->isEditing = true;
            $this->version = (int) $container->version;
            $this->status = (string) $container->status;
            $this->readonly = $container->status !== 'Draft';
            $this->canCancel = $container->status === 'Draft';

            session()->flash('status', __('ui.placement.form.saved'));
            $this->redirect(route('placements.show', $this->containerId));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->mapValidation($exception);
        }
    }

    public function submit(): void
    {
        $this->clearActionState();

        if (! $this->isEditing) {
            $this->saveDraft();

            if ($this->conflict || $this->serverErrors !== [] || $this->actionError !== null) {
                return;
            }
        }

        try {
            $container = app(PlacementContainerService::class)
                ->submit(Auth::user(), (int) $this->containerId, ['version' => $this->version]);

            session()->flash('status', __('ui.placement.form.submitted'));
            $this->redirect(route('placements.show', (int) $container->id));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->mapValidation($exception);
        }
    }

    public function cancel(): void
    {
        $this->clearActionState();

        try {
            app(PlacementContainerService::class)
                ->cancel(Auth::user(), (int) $this->containerId, ['version' => $this->version]);

            session()->flash('status', __('ui.placement.form.cancelled'));
            $this->redirect(route('placements.index'));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->mapValidation($exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'nama' => $this->nama,
            'perusahaan_id' => $this->perusahaanId !== '' ? (int) $this->perusahaanId : null,
        ];
    }

    private function mapValidation(ValidationException $exception): void
    {
        $this->serverErrors = [];

        foreach ($exception->errors() as $field => $messages) {
            $message = (string) collect($messages)->first();
            $translationKey = 'ui.placement.errors.'.$message;
            $translated = __($translationKey);
            $this->serverErrors[$field] = $translated === $translationKey ? $message : $translated;
        }

        $nonFieldError = collect(['version', 'nama', 'perusahaan_id', 'status', 'container'])
            ->first(fn (string $field): bool => isset($this->serverErrors[$field]));
        $this->actionError = $nonFieldError !== null
            ? $this->serverErrors[$nonFieldError]
            : __('ui.placement.errors.VALIDATION_FAILED');
    }

    private function clearActionState(): void
    {
        $this->actionError = null;
        $this->serverErrors = [];
        $this->conflict = false;
    }
}
