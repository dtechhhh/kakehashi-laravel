<?php

namespace Modules\LookupData\Public;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Public\StepUpService;
use Modules\Auth\StepUpAction;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;

final class LookupAdminService
{
    /** Fixed scope ID for create tokens; entity type is table-specific (`lookup_create:{table}`). */
    private const STEP_UP_SCOPE_CREATE = 1;

    private const PROTECTED_COLUMNS = [
        'id',
        'code',
        'is_active',
        'created_at',
        'updated_at',
    ];

    public function __construct(
        private readonly LookupService $lookup,
        private readonly StepUpService $stepUp,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, string $table, array $attributes): object
    {
        return DB::transaction(function () use ($actor, $attributes, $table): object {
            $this->authorize($actor);
            $values = $this->validateForCreate($table, $attributes);

            $this->stepUp->require(
                StepUpAction::MANAGE_LOOKUP_OR_COMPANY,
                'lookup_create:'.$table,
                self::STEP_UP_SCOPE_CREATE,
            );

            try {
                $id = DB::table($table)->insertGetId($values + [
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $exception) {
                $this->rethrowUniqueViolation($exception);
            }

            $row = DB::table($table)->where('id', $id)->first();

            $this->audit->record(
                actionType: ActionType::LOOKUP_CREATED,
                entityType: 'lookup',
                entityId: $id,
                detail: $this->detail($table, $row),
                actorId: $actor->getKey(),
            );

            $this->flushAfterCommit($table);

            return $row;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, string $table, int $id, array $attributes): object
    {
        return DB::transaction(function () use ($actor, $attributes, $id, $table): object {
            $this->authorize($actor);
            $this->lookup->assertTable($table);
            $row = DB::table($table)->where('id', $id)->lockForUpdate()->firstOrFail();
            $values = $this->validated($table, $attributes, false);
            $changed = $this->changed($row, $values);

            if ($changed === []) {
                return $row;
            }

            $this->stepUp->require(
                StepUpAction::MANAGE_LOOKUP_OR_COMPANY,
                'lookup:'.$table,
                $id,
            );

            $updates = array_map(static fn (array $change): mixed => $change[1], $changed);

            DB::table($table)->where('id', $id)->update($updates + ['updated_at' => now()]);

            $this->audit->record(
                actionType: ActionType::LOOKUP_UPDATED,
                entityType: 'lookup',
                entityId: $id,
                detail: [
                    'lookup_category' => $table,
                    'code' => $row->code,
                    'changed' => $changed,
                ],
                actorId: $actor->getKey(),
            );

            $this->flushAfterCommit($table);

            return DB::table($table)->where('id', $id)->first();
        });
    }

    public function deactivate(User $actor, string $table, int $id): object
    {
        return $this->setActive($actor, $table, $id, false);
    }

    public function reactivate(User $actor, string $table, int $id): object
    {
        return $this->setActive($actor, $table, $id, true);
    }

    private function setActive(User $actor, string $table, int $id, bool $active): object
    {
        return DB::transaction(function () use ($active, $actor, $id, $table): object {
            $this->authorize($actor);
            $this->lookup->assertTable($table);
            $row = DB::table($table)->where('id', $id)->lockForUpdate()->firstOrFail();

            if ((bool) $row->is_active === $active) {
                return $row;
            }

            $this->stepUp->require(
                StepUpAction::MANAGE_LOOKUP_OR_COMPANY,
                'lookup:'.$table,
                $id,
            );

            DB::table($table)->where('id', $id)->update([
                'is_active' => $active,
                'updated_at' => now(),
            ]);

            $this->audit->record(
                actionType: $active ? ActionType::LOOKUP_REACTIVATED : ActionType::LOOKUP_DEACTIVATED,
                entityType: 'lookup',
                entityId: $id,
                detail: $active ? [
                    'lookup_category' => $table,
                    'code' => $row->code,
                    'changed' => ['is_active' => [false, true]],
                ] : $this->detail($table, $row),
                actorId: $actor->getKey(),
            );

            $this->flushAfterCommit($table);

            return DB::table($table)->where('id', $id)->first();
        });
    }

    private function authorize(User $actor): void
    {
        if ((int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException('LOOKUP_ADMIN_ONLY');
        }

        Gate::forUser($actor)->authorize('lookup.manage');
    }

    private function flushAfterCommit(string $table): void
    {
        DB::afterCommit(function () use ($table): void {
            $this->lookup->flush($table);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function validateForCreate(string $table, array $attributes): array
    {
        return $this->validated($table, $attributes, true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validated(string $table, array $attributes, bool $creating): array
    {
        $this->lookup->assertTable($table);

        if (! $creating && array_key_exists('code', $attributes)) {
            $this->fail('code', 'LOOKUP_CODE_IMMUTABLE');
        }

        $allowed = array_diff(Schema::getColumnListing($table), self::PROTECTED_COLUMNS);
        $unknown = array_diff(array_keys($attributes), array_merge(['code'], $allowed));

        if ($unknown !== []) {
            $this->fail('attributes', 'LOOKUP_FIELD_UNKNOWN');
        }

        $attributes = array_map(
            static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value,
            $attributes,
        );

        $rules = [
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:64', 'not_regex:/^\s*$/'],
            'label_id' => [$creating ? 'required' : 'sometimes', 'string', 'max:255', 'not_regex:/^\s*$/'],
            'label_ja' => [$creating ? 'required' : 'sometimes', 'string', 'max:255', 'not_regex:/^\s*$/'],
            'sort_order' => ['sometimes', 'integer'],
        ];

        if (array_key_exists('code', $attributes)) {
            $pattern = match ($table) {
                'negara' => '/^[A-Z]{2}$/',
                'bahasa' => '/^[a-z]{2}$/',
                default => '/^[A-Z0-9_]+$/',
            };
            $rules['code'][] = 'regex:'.$pattern;
        }

        foreach (array_diff($allowed, ['label_id', 'label_ja', 'sort_order']) as $column) {
            $rules[$column] = match ($column) {
                'is_shareable' => ['sometimes', 'boolean'],
                'negara_id', 'provinsi_id', 'kota_kabupaten_id', 'bidang_pekerjaan_id', 'bidang_id' => ['sometimes', 'nullable', 'integer'],
                default => ['sometimes', 'nullable', 'string', 'max:255'],
            };
        }

        $values = Validator::make($attributes, $rules)->validate();

        foreach (['negara_id' => 'negara', 'provinsi_id' => 'provinsi', 'kota_kabupaten_id' => 'kota_kabupaten', 'bidang_pekerjaan_id' => 'bidang_pekerjaan', 'bidang_id' => 'bidang_pekerjaan'] as $column => $parent) {
            if (array_key_exists($column, $values) && $values[$column] !== null
                && ! DB::table($parent)->where('id', $values[$column])->where('is_active', true)->exists()) {
                $this->fail($column, 'LOOKUP_PARENT_INACTIVE');
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function changed(object $row, array $values): array
    {
        $changed = [];

        foreach ($values as $column => $value) {
            if ($row->{$column} != $value) {
                $changed[$column] = [$row->{$column}, $value];
            }
        }

        return $changed;
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(string $table, object $row): array
    {
        return [
            'lookup_category' => $table,
            'code' => $row->code,
            'label_id' => $row->label_id,
            'label_ja' => $row->label_ja,
        ];
    }

    private function rethrowUniqueViolation(QueryException $exception): never
    {
        if ($exception->getCode() === '23505') {
            $this->fail('code', 'LOOKUP_CODE_EXISTS');
        }

        throw $exception;
    }

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code]);
    }
}
