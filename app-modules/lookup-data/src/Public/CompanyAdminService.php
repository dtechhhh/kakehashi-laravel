<?php

namespace Modules\LookupData\Public;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Public\StepUpService;
use Modules\Auth\StepUpAction;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;

/**
 * Super Admin master-perusahaan mutations (PRD §5.4 / W2-T6).
 * Soft-disable only; no hard-delete path.
 */
final class CompanyAdminService
{
    /** Distinct from mutation scope `perusahaan.{id}` so create tokens cannot mutate ID 1. */
    public const STEP_UP_ENTITY_CREATE = 'perusahaan_create';

    private const STEP_UP_SCOPE_CREATE = 1;

    private const MUTABLE = [
        'nama_ja',
        'nama_romaji',
        'nama_id',
        'negara_id',
        'bidang_industri_id',
        'alamat',
    ];

    public function __construct(
        private readonly StepUpService $stepUp,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * PRD §5.4: negara default Jepang. Reads PostgreSQL only (not Redis).
     */
    public function resolveDefaultNegaraId(): int
    {
        $id = DB::table('negara')
            ->where('code', 'JP')
            ->where('is_active', true)
            ->value('id');

        if ($id === null) {
            $this->fail('negara_id', 'COMPANY_DEFAULT_NEGARA_JP_MISSING');
        }

        return (int) $id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, array $attributes): object
    {
        return DB::transaction(function () use ($actor, $attributes): object {
            $this->authorize($actor);
            $values = $this->validated($attributes, true);

            if (! array_key_exists('negara_id', $values) || $values['negara_id'] === null) {
                $values['negara_id'] = $this->resolveDefaultNegaraId();
            }

            $this->stepUp->require(
                StepUpAction::MANAGE_LOOKUP_OR_COMPANY,
                self::STEP_UP_ENTITY_CREATE,
                self::STEP_UP_SCOPE_CREATE,
            );

            $id = DB::table('perusahaan')->insertGetId($values + [
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('perusahaan')->where('id', $id)->firstOrFail();

            $this->audit->record(
                actionType: ActionType::COMPANY_CREATED,
                entityType: 'perusahaan',
                entityId: $id,
                detail: [
                    'perusahaan_id' => $id,
                    'nama_ja' => $row->nama_ja,
                ],
                actorId: $actor->getKey(),
            );

            return $row;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, int $id, array $attributes): object
    {
        return DB::transaction(function () use ($actor, $attributes, $id): object {
            $this->authorize($actor);
            $row = DB::table('perusahaan')->where('id', $id)->lockForUpdate()->firstOrFail();
            $values = $this->validated($attributes, false);
            $changed = $this->changed($row, $values);

            if ($changed === []) {
                return $row;
            }

            $this->stepUp->require(
                StepUpAction::MANAGE_LOOKUP_OR_COMPANY,
                'perusahaan',
                $id,
            );

            $updates = array_map(static fn (array $change): mixed => $change[1], $changed);

            DB::table('perusahaan')->where('id', $id)->update($updates + ['updated_at' => now()]);

            $updated = DB::table('perusahaan')->where('id', $id)->firstOrFail();

            $this->audit->record(
                actionType: ActionType::COMPANY_UPDATED,
                entityType: 'perusahaan',
                entityId: $id,
                detail: [
                    'perusahaan_id' => $id,
                    'nama_ja' => $updated->nama_ja,
                    'changed' => $changed,
                ],
                actorId: $actor->getKey(),
            );

            return $updated;
        });
    }

    public function deactivate(User $actor, int $id): object
    {
        return $this->setActive($actor, $id, false);
    }

    public function reactivate(User $actor, int $id): object
    {
        return $this->setActive($actor, $id, true);
    }

    private function setActive(User $actor, int $id, bool $active): object
    {
        return DB::transaction(function () use ($active, $actor, $id): object {
            $this->authorize($actor);
            $row = DB::table('perusahaan')->where('id', $id)->lockForUpdate()->firstOrFail();

            if ((bool) $row->is_active === $active) {
                return $row;
            }

            $this->stepUp->require(
                StepUpAction::MANAGE_LOOKUP_OR_COMPANY,
                'perusahaan',
                $id,
            );

            DB::table('perusahaan')->where('id', $id)->update([
                'is_active' => $active,
                'updated_at' => now(),
            ]);

            $this->audit->record(
                actionType: $active ? ActionType::COMPANY_REACTIVATED : ActionType::COMPANY_DEACTIVATED,
                entityType: 'perusahaan',
                entityId: $id,
                detail: [
                    'perusahaan_id' => $id,
                    'nama_ja' => $row->nama_ja,
                    'changed' => ['is_active' => [(bool) $row->is_active, $active]],
                ],
                actorId: $actor->getKey(),
            );

            return DB::table('perusahaan')->where('id', $id)->firstOrFail();
        });
    }

    private function authorize(User $actor): void
    {
        if ((int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException('COMPANY_ADMIN_ONLY');
        }

        Gate::forUser($actor)->authorize('company.manage');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validated(array $attributes, bool $creating): array
    {
        $unknown = array_diff(array_keys($attributes), self::MUTABLE);

        if ($unknown !== []) {
            $this->fail('attributes', 'COMPANY_FIELD_UNKNOWN');
        }

        $attributes = array_map(
            static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value,
            $attributes,
        );

        $rules = [
            'nama_ja' => [$creating ? 'required' : 'sometimes', 'string', 'max:255', 'not_regex:/^\s*$/'],
            'nama_romaji' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nama_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'negara_id' => ['sometimes', 'nullable', 'integer'],
            'bidang_industri_id' => ['sometimes', 'nullable', 'integer'],
            'alamat' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];

        $values = Validator::make($attributes, $rules)->validate();

        foreach (['negara_id' => 'negara', 'bidang_industri_id' => 'bidang_industri_perusahaan'] as $column => $parent) {
            if (array_key_exists($column, $values) && $values[$column] !== null
                && ! DB::table($parent)->where('id', $values[$column])->where('is_active', true)->exists()) {
                $this->fail($column, 'COMPANY_PARENT_INACTIVE');
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, array{0: mixed, 1: mixed}>
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

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code]);
    }
}
