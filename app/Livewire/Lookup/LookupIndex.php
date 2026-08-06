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
use Modules\LookupData\Public\LookupAdminService;
use Modules\LookupData\Public\LookupService;

/**
 * S1 — bilingual lookup CRUD (Super Admin).
 *
 * All mutations require step-up `MANAGE_LOOKUP_OR_COMPANY` scoped per table
 * (`lookup_create:{table}.1` for create; `lookup:{table}.{id}` otherwise).
 * `code` is immutable on edit; soft-disable replaces hard-delete; lookup
 * values never include state-machine statuses. No optimistic version exists
 * here, so stale edits are last-write-wins (no 409 reload needed).
 */
final class LookupIndex extends Component
{
    use WithPagination;

    public string $table = 'negara';

    public string $search = '';

    public string $active = '';

    public string $sort = 'sort_order';

    public string $direction = 'asc';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $formCode = '';

    public string $formLabelId = '';

    public string $formLabelJa = '';

    public string $formSortOrder = '0';

    /**
     * @var array<string, string>
     */
    public array $formExtras = [];

    public ?string $actionError = null;

    public ?string $actionSuccess = null;

    /**
     * @var array{type: string, table: string, id?: int, attributes?: array<string, mixed>}|null
     */
    public ?array $pending = null;

    public function render()
    {
        Gate::authorize('lookup.manage');

        $lookup = app(LookupService::class);

        $rows = $lookup->paginate($this->table, [
            'search' => $this->search,
            'active' => $this->active,
            'sort' => $this->sort,
            'direction' => $this->direction,
        ]);

        return view('livewire.lookup.lookup-index', [
            'tables' => $lookup->tables(),
            'rows' => $rows,
            'extraColumns' => $this->extraColumns(),
            'parentOptions' => $this->parentOptions(),
        ]);
    }

    public function updatedTable(): void
    {
        $this->resetPage();
        $this->showForm = false;
        $this->actionError = null;
        $this->actionSuccess = null;
    }

    public function sortBy(string $column): void
    {
        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = 'asc';
        }

        $this->resetPage();
    }

    public function startCreate(): void
    {
        $this->showForm = true;
        $this->editingId = null;
        $this->resetFormFields();
    }

    public function startEdit(int $id): void
    {
        $row = app(LookupService::class)->find($this->table, $id);

        if ($row === null) {
            $this->actionError = __('ui.lookup.errors.NOT_FOUND');

            return;
        }

        $this->showForm = true;
        $this->editingId = $id;
        $this->formCode = (string) $row->code;
        $this->formLabelId = (string) $row->label_id;
        $this->formLabelJa = (string) $row->label_ja;
        $this->formSortOrder = (string) $row->sort_order;
        $this->formExtras = [];

        foreach ($this->extraColumns() as $column) {
            $value = $row->{$column['name']} ?? null;
            $this->formExtras[$column['name']] = $value === null ? '' : (string) $value;
        }
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
            'label_id' => $this->formLabelId,
            'label_ja' => $this->formLabelJa,
            'sort_order' => $this->formSortOrder === '' ? 0 : (int) $this->formSortOrder,
        ];

        foreach ($this->extraColumns() as $column) {
            $raw = $this->formExtras[$column['name']] ?? '';
            $attributes[$column['name']] = $column['type'] === 'bool'
                ? ($raw === '1' || $raw === 'true')
                : $raw;
        }

        if ($this->editingId === null) {
            $attributes['code'] = $this->formCode;
            $this->pending = [
                'type' => 'create',
                'table' => $this->table,
                'attributes' => $attributes,
            ];
            $this->requireStepUpOrExecute('lookup_create:'.$this->table, 1);
        } else {
            $this->pending = [
                'type' => 'update',
                'table' => $this->table,
                'id' => $this->editingId,
                'attributes' => $attributes,
            ];
            $this->requireStepUpOrExecute('lookup:'.$this->table, $this->editingId);
        }
    }

    public function toggleActive(int $id, bool $active): void
    {
        $this->pending = [
            'type' => $active ? 'reactivate' : 'deactivate',
            'table' => $this->table,
            'id' => $id,
        ];
        $this->requireStepUpOrExecute('lookup:'.$this->table, $id);
    }

    #[On('stepup.success')]
    public function handleStepUpSuccess(string $action, string $entityType, int $entityId): void
    {
        if ($this->pending === null || $action !== StepUpAction::MANAGE_LOOKUP_OR_COMPANY) {
            return;
        }

        $expectedType = $this->pending['type'] === 'create'
            ? 'lookup_create:'.$this->pending['table']
            : 'lookup:'.$this->pending['table'];

        if ($entityType !== $expectedType) {
            return;
        }

        $expectedId = $this->pending['type'] === 'create' ? 1 : $this->pending['id'];
        if ($entityId !== $expectedId) {
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
        $actor = Auth::user();
        $service = app(LookupAdminService::class);

        try {
            match ($pending['type']) {
                'create' => $service->create($actor, $pending['table'], $pending['attributes']),
                'update' => $service->update($actor, $pending['table'], $pending['id'], $pending['attributes']),
                'deactivate' => $service->deactivate($actor, $pending['table'], $pending['id']),
                'reactivate' => $service->reactivate($actor, $pending['table'], $pending['id']),
                default => null,
            };

            $this->actionError = null;
            $this->actionSuccess = __('ui.lookup.success_saved');
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
            return __('ui.lookup.errors.'.$exception->getMessage(), [], app()->getLocale());
        }

        $code = collect($exception->errors())->flatten()->first();
        $translated = is_string($code) ? __('ui.lookup.errors.'.$code, [], app()->getLocale()) : null;

        if (is_string($translated) && $translated !== 'ui.lookup.errors.'.$code) {
            return $translated;
        }

        return is_string($code) ? $code : __('ui.lookup.errors.GENERIC');
    }

    private function resetFormFields(): void
    {
        $this->formCode = '';
        $this->formLabelId = '';
        $this->formLabelJa = '';
        $this->formSortOrder = '0';
        $this->formExtras = [];
    }

    /**
     * Extra columns metadata delegated to the admin service (allowlisted
     * tables, schema-driven) so the S1 form renders only relevant fields.
     *
     * @return list<array{name: string, type: string, table?: string}>
     */
    public function extraColumns(): array
    {
        return app(LookupAdminService::class)->extraColumns($this->table);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function parentOptions(): array
    {
        $options = [];

        foreach ($this->extraColumns() as $column) {
            if ($column['type'] !== 'lookup') {
                continue;
            }

            $options[$column['name']] = app(LookupService::class)->optionsById($column['table'], app()->getLocale());
        }

        return $options;
    }
}
