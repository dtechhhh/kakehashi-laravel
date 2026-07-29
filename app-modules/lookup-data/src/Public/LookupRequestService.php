<?php

namespace Modules\LookupData\Public;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Public\StepUpService;
use Modules\Auth\Rbac;
use Modules\Auth\StepUpAction;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;
use Shared\Notifications\NotificationService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class LookupRequestService
{
    public function __construct(
        private readonly LookupService $lookup,
        private readonly LookupAdminService $admin,
        private readonly StepUpService $stepUp,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function submitLookup(User $actor, array $attributes): object
    {
        $this->authorize($actor, 'lookup.request.submit');
        $table = $attributes['lookup_table'] ?? null;

        if (! is_string($table)) {
            $this->fail('lookup_table', 'LOOKUP_REQUEST_TABLE');
        }

        $values = $this->admin->validateForCreate($table, array_diff_key($attributes, array_flip(['lookup_table', 'reason'])));
        $reason = $this->reason($attributes['reason'] ?? null);
        $extra = array_diff_key($values, array_flip(['code', 'label_id', 'label_ja']));

        return DB::transaction(function () use ($actor, $extra, $reason, $table, $values): object {
            $id = DB::table('lookup_request')->insertGetId([
                'lookup_table' => $table,
                'code' => $values['code'],
                'label_id' => $values['label_id'],
                'label_ja' => $values['label_ja'],
                'extra' => $extra === [] ? null : json_encode($extra, JSON_THROW_ON_ERROR),
                'requested_by' => $actor->getKey(),
                'reason' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(ActionType::LOOKUP_REQUEST_SUBMITTED, 'lookup_request', $id, [
                'lookup_request_id' => $id,
                'lookup_table' => $table,
                'code' => $values['code'],
            ], $actor->getKey());
            $this->notifySuperAdmins(ActionType::LOOKUP_REQUEST_SUBMITTED, ['lookup_request_id' => $id]);

            return DB::table('lookup_request')->where('id', $id)->firstOrFail();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function submitCompany(User $actor, array $attributes): object
    {
        $this->authorize($actor, 'company.request.submit');
        $attributes = Arr::only($attributes, ['nama_ja', 'nama_romaji', 'nama_id', 'reason']);
        $values = Validator::make($attributes, [
            'nama_ja' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
            'nama_romaji' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nama_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ])->validate();

        foreach (['nama_ja', 'nama_romaji', 'nama_id'] as $field) {
            if (isset($values[$field]) && is_string($values[$field])) {
                $values[$field] = trim($values[$field]);
            }
        }
        $reason = $this->reason($attributes['reason'] ?? null);

        return DB::transaction(function () use ($actor, $reason, $values): object {
            $id = DB::table('company_request')->insertGetId($values + [
                'requested_by' => $actor->getKey(),
                'reason' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(ActionType::COMPANY_REQUESTED, 'company_request', $id, [
                'company_request_id' => $id,
            ], $actor->getKey());
            $this->notifySuperAdmins(ActionType::COMPANY_REQUESTED, ['company_request_id' => $id]);

            return DB::table('company_request')->where('id', $id)->firstOrFail();
        });
    }

    public function approveLookup(User $actor, int $requestId): object
    {
        return $this->decide($actor, 'lookup_request', $requestId, 'approved', null);
    }

    public function rejectLookup(User $actor, int $requestId, string $note): object
    {
        return $this->decide($actor, 'lookup_request', $requestId, 'rejected', $note);
    }

    public function approveCompany(User $actor, int $requestId): object
    {
        return $this->decide($actor, 'company_request', $requestId, 'approved', null);
    }

    public function rejectCompany(User $actor, int $requestId, string $note): object
    {
        return $this->decide($actor, 'company_request', $requestId, 'rejected', $note);
    }

    private function decide(User $actor, string $table, int $requestId, string $decision, ?string $note): object
    {
        $this->authorize($actor, match ($table) {
            'lookup_request' => 'lookup.request.decide',
            'company_request' => 'company.request.decide',
        });
        $note = $decision === 'rejected' ? $this->rejectionNote($note) : null;

        return DB::transaction(function () use ($actor, $decision, $note, $requestId, $table): object {
            $request = DB::table($table)->where('id', $requestId)->first();

            if ($request === null) {
                throw new NotFoundHttpException('Request not found.');
            }
            if ((int) $request->requested_by === (int) $actor->getKey()) {
                throw new AccessDeniedHttpException('APV_SELF');
            }
            if ($request->status !== 'pending') {
                throw new ConflictHttpException('APV_DONE');
            }

            $this->stepUp->require(StepUpAction::MANAGE_LOOKUP_OR_COMPANY, $table, $requestId);

            $now = now();
            $affected = DB::table($table)
                ->where('id', $requestId)
                ->where('status', 'pending')
                ->update([
                    'status' => $decision,
                    'reviewed_by' => $actor->getKey(),
                    'note_checker' => $note,
                    'reviewed_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($affected !== 1) {
                throw new ConflictHttpException('APV_DONE');
            }

            if ($decision === 'approved') {
                $this->createTarget($table, $request);
            }

            $action = $this->action($table, $decision);
            $this->audit->record($action, $table, $requestId, $this->detail($table, $requestId, $request), $actor->getKey());
            $this->notifyMaker((int) $request->requested_by, $action, [$table.'_id' => $requestId]);

            if ($table === 'lookup_request' && $decision === 'approved') {
                DB::afterCommit(fn (): mixed => $this->lookup->flush($request->lookup_table));
            }

            return DB::table($table)->where('id', $requestId)->firstOrFail();
        });
    }

    private function createTarget(string $table, object $request): void
    {
        try {
            if ($table === 'lookup_request') {
                $extra = $request->extra === null
                    ? []
                    : json_decode($request->extra, true, 512, JSON_THROW_ON_ERROR);
                $values = $this->admin->validateForCreate($request->lookup_table, array_merge($extra, [
                    'code' => $request->code,
                    'label_id' => $request->label_id,
                    'label_ja' => $request->label_ja,
                ]));

                DB::table($request->lookup_table)->insert($values + [
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return;
            }

            DB::table('perusahaan')->insert([
                'nama_ja' => $request->nama_ja,
                'nama_romaji' => $request->nama_romaji,
                'nama_id' => $request->nama_id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ($table === 'lookup_request' && $exception->getCode() === '23505') {
                $this->fail('code', 'LOOKUP_CODE_EXISTS');
            }

            throw $exception;
        }
    }

    private function authorize(User $actor, string $ability): void
    {
        if ((int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException('LOOKUP_REQUEST_ACTOR_MISMATCH');
        }

        Gate::forUser($actor)->authorize($ability);
    }

    private function reason(mixed $reason): ?string
    {
        return Validator::make(['reason' => $reason], ['reason' => ['nullable', 'string', 'max:5000']])->validate()['reason'] ?? null;
    }

    private function rejectionNote(?string $note): string
    {
        $note = is_string($note) ? trim($note) : '';

        if ($note === '') {
            $this->fail('note_checker', 'APV_NOTE');
        }

        return $note;
    }

    private function action(string $table, string $decision): ActionType
    {
        return match ([$table, $decision]) {
            ['lookup_request', 'approved'] => ActionType::LOOKUP_REQUEST_APPROVED,
            ['lookup_request', 'rejected'] => ActionType::LOOKUP_REQUEST_REJECTED,
            ['company_request', 'approved'] => ActionType::COMPANY_APPROVED,
            ['company_request', 'rejected'] => ActionType::COMPANY_REJECTED,
        };
    }

    /** @return array<string, mixed> */
    private function detail(string $table, int $requestId, object $request): array
    {
        return $table === 'lookup_request'
            ? ['lookup_request_id' => $requestId, 'lookup_table' => $request->lookup_table, 'code' => $request->code]
            : ['company_request_id' => $requestId];
    }

    /** @param array<string, mixed> $payload */
    private function notifySuperAdmins(ActionType $action, array $payload): void
    {
        $userIds = $this->superAdminIds();
        $this->notifications->notifyInApp($userIds, $action->value, $payload);
        $this->notifications->queueEmailAfterCommit($userIds, $action->value, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function notifyMaker(int $userId, ActionType $action, array $payload): void
    {
        $this->notifications->notifyInApp([$userId], $action->value, $payload);
        $this->notifications->queueEmailAfterCommit([$userId], $action->value, $payload);
    }

    /** @return list<int> */
    private function superAdminIds(): array
    {
        return User::role(Rbac::SUPER_ADMIN)->where('status_akun', 'Aktif')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code]);
    }
}
