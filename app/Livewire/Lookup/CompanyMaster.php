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
use Modules\LookupData\Public\CompanyAdminService;
use Modules\LookupData\Public\LookupService;

/**
 * S3 — company master (Super Admin).
 *
 * `nama_ja` is required; negara defaults to Japan (JP) server-side. All
 * mutations require step-up `MANAGE_LOOKUP_OR_COMPANY` with distinct scopes:
 * `perusahaan_create.1` for create and `perusahaan.{id}` otherwise.
 * Soft-disable only. No optimistic version exists here.
 */
final class CompanyMaster extends Component
{
    use WithPagination;

    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $formNamaJa = '';

    public string $formNamaRomaji = '';

    public string $formNamaId = '';

    public string $formAlamat = '';

    public string $formNegaraId = '';

    public string $formBidangIndustriId = '';

    public ?string $actionError = null;

    public ?string $actionSuccess = null;

    /**
     * @var array{type: string, id?: int, attributes?: array<string, mixed>}|null
     */
    public ?array $pending = null;

    public function render()
    {
        Gate::authorize('company.manage');

        $companies = app(CompanyAdminService::class)->paginate(Auth::user(), trim($this->search));
        $lookup = app(LookupService::class);

        return view('livewire.lookup.company-master', [
            'companies' => $companies,
            'negaraOptions' => $lookup->optionsById('negara', app()->getLocale()),
            'negaraLabels' => $companies->getCollection()->mapWithKeys(
                fn (object $company): array => [
                    $company->id => $lookup->labelById('negara', $company->negara_id, app()->getLocale()),
                ],
            )->all(),
            'industriOptions' => $lookup->optionsById('bidang_industri_perusahaan', app()->getLocale()),
        ]);
    }

    public function startCreate(): void
    {
        $this->showForm = true;
        $this->editingId = null;
        $this->resetFormFields();
    }

    public function startEdit(int $id): void
    {
        $row = app(CompanyAdminService::class)->find(Auth::user(), $id);

        if ($row === null) {
            $this->actionError = __('ui.company.errors.NOT_FOUND');

            return;
        }

        $this->showForm = true;
        $this->editingId = $id;
        $this->formNamaJa = (string) $row->nama_ja;
        $this->formNamaRomaji = (string) ($row->nama_romaji ?? '');
        $this->formNamaId = (string) ($row->nama_id ?? '');
        $this->formAlamat = (string) ($row->alamat ?? '');
        $this->formNegaraId = (string) ($row->negara_id ?? '');
        $this->formBidangIndustriId = (string) ($row->bidang_industri_id ?? '');
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->resetFormFields();
    }

    public function save(): void
    {
        $attributes = [
            'nama_ja' => $this->formNamaJa,
            'nama_romaji' => $this->formNamaRomaji,
            'nama_id' => $this->formNamaId,
            'alamat' => $this->formAlamat,
            'negara_id' => $this->formNegaraId === '' ? null : (int) $this->formNegaraId,
            'bidang_industri_id' => $this->formBidangIndustriId === '' ? null : (int) $this->formBidangIndustriId,
        ];

        $service = app(CompanyAdminService::class);

        if ($this->editingId === null) {
            $this->pending = ['type' => 'create', 'attributes' => $attributes];
            $this->requireStepUpOrExecute(CompanyAdminService::STEP_UP_ENTITY_CREATE, 1);
        } else {
            $this->pending = ['type' => 'update', 'id' => $this->editingId, 'attributes' => $attributes];
            $this->requireStepUpOrExecute('perusahaan', $this->editingId);
        }
    }

    public function toggleActive(int $id, bool $active): void
    {
        $this->pending = ['type' => $active ? 'reactivate' : 'deactivate', 'id' => $id];
        $this->requireStepUpOrExecute('perusahaan', $id);
    }

    #[On('stepup.success')]
    public function handleStepUpSuccess(string $action, string $entityType, int $entityId): void
    {
        if ($this->pending === null || $action !== StepUpAction::MANAGE_LOOKUP_OR_COMPANY) {
            return;
        }

        $expectedType = $this->pending['type'] === 'create'
            ? CompanyAdminService::STEP_UP_ENTITY_CREATE
            : 'perusahaan';

        $expectedId = $this->pending['type'] === 'create' ? 1 : $this->pending['id'];

        if ($entityType !== $expectedType || $entityId !== $expectedId) {
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
        $service = app(CompanyAdminService::class);
        $actor = Auth::user();

        try {
            match ($pending['type']) {
                'create' => $service->create($actor, $pending['attributes']),
                'update' => $service->update($actor, $pending['id'], $pending['attributes']),
                'deactivate' => $service->deactivate($actor, $pending['id']),
                'reactivate' => $service->reactivate($actor, $pending['id']),
                default => null,
            };

            $this->actionError = null;
            $this->actionSuccess = __('ui.company.success_saved');
            $this->showForm = false;
            $this->editingId = null;
            $this->resetFormFields();
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
            return __('ui.company.errors.'.$exception->getMessage(), [], app()->getLocale());
        }

        $code = collect($exception->errors())->flatten()->first();
        $translated = is_string($code) ? __('ui.company.errors.'.$code, [], app()->getLocale()) : null;

        if (is_string($translated) && $translated !== 'ui.company.errors.'.$code) {
            return $translated;
        }

        return is_string($code) ? $code : __('ui.company.errors.GENERIC');
    }

    private function resetFormFields(): void
    {
        $this->formNamaJa = '';
        $this->formNamaRomaji = '';
        $this->formNamaId = '';
        $this->formAlamat = '';
        $this->formNegaraId = '';
        $this->formBidangIndustriId = '';
    }
}
