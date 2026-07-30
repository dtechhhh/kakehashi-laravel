<?php

namespace Modules\Candidates\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Rbac;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Modules\Candidates\Enums\CandidateAvailability;
use Shared\Approval\PendingRequestService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;
use Shared\Notifications\NotificationService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * W3-T5 / FIX1 — revision of Disetujui main: one active snapshot, submit pending, no NIK.
 *
 * Approve/reject merge lives in CandidateApprovalService.
 */
final class CandidateRevisionService
{
    /** Mutable main fields copied into revision and merged back on approve. */
    public const MAIN_FIELDS = [
        'nama_alphabet',
        'nama_katakana',
        'tanggal_lahir',
        'tempat_lahir_kota_id',
        'alamat_detail',
        'email',
        'phone',
        'line_id',
        'kewarganegaraan_id',
        'asal_rekrutmen_id',
        'agama_id',
        'alamat_provinsi_id',
        'alamat_kota_kabupaten_id',
        'alamat_kecamatan_id',
        'jenis_kelamin',
        'status_pernikahan',
        'catatan_tambahan',
    ];

    /**
     * Child tables in revision snapshot/diff/merge (photo = DB metadata only; R2 is W3-T7).
     *
     * @var list<string>
     */
    public const CHILD_TABLES = [
        'candidate_physical',
        'candidate_education',
        'candidate_work',
        'candidate_qual_english',
        'candidate_qual_japanese',
        'candidate_qual_ssw',
        'candidate_qual_driving',
        'candidate_qual_other',
        'candidate_self_promo',
        'candidate_family',
        'candidate_family_contact',
        'candidate_immigration',
        'candidate_document',
        'candidate_photo',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PendingRequestService $pending,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Snapshot main (Disetujui) into a new revision row. Main stays operational.
     *
     * @param  array{version?: int|string}  $options  main.version optimistic lock
     */
    public function createRevision(User $actor, int $mainId, array $options = []): object
    {
        return DB::transaction(function () use ($actor, $mainId, $options): object {
            $this->authorizeCreate($actor);

            $main = DB::table('candidate')->where('id', $mainId)->lockForUpdate()->first();
            if ($main === null) {
                $this->fail('candidate', 'CANDIDATE_NOT_FOUND');
            }

            $this->assertRevisableMain($main);

            $expectedVersion = $this->requireVersion($options);
            if ($expectedVersion !== (int) $main->version) {
                throw new ConflictHttpException('CONFLICT');
            }

            // Normalize active-revision collision to 409 (serial path + race via unique).
            if ($this->hasOpenRevision($mainId)) {
                throw new ConflictHttpException('CANDIDATE_REVISION_ACTIVE');
            }

            $mainFields = [];
            foreach (self::MAIN_FIELDS as $field) {
                $mainFields[$field] = $main->{$field};
            }

            try {
                $revisionId = DB::table('candidate')->insertGetId([
                    ...$mainFields,
                    'nomor_induk' => null,
                    'status_ketersediaan' => CandidateAvailability::Tersedia->value,
                    'status_approval' => CandidateApprovalStatus::Draft->value,
                    'parent_candidate_id' => $mainId,
                    'version' => 0,
                    'created_by' => $actor->getKey(),
                    'approved_by' => null,
                    'catatan_penolakan_terakhir' => null,
                    'deleted_at' => null,
                    'pii_anonymized_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                if ($this->isActiveRevisionUniqueViolation($exception)) {
                    throw new ConflictHttpException('CANDIDATE_REVISION_ACTIVE', $exception);
                }

                throw $exception;
            }

            $this->cloneChildren($mainId, $revisionId);

            $this->audit->record(
                actionType: ActionType::CANDIDATE_UPDATED,
                entityType: 'candidate',
                entityId: $mainId,
                detail: [
                    'revision_id' => $revisionId,
                    'parent_candidate_id' => $mainId,
                    'status_approval' => CandidateApprovalStatus::Draft->value,
                    'has_nomor_induk' => false,
                    'version' => 0,
                ],
                actorId: $actor->getKey(),
            );

            $revision = $this->findOrFail($revisionId);
            $this->assertRevisionDraftGate($revision);

            $freshMain = $this->findOrFail($mainId);
            if ($freshMain->status_approval !== CandidateApprovalStatus::Disetujui->value
                || $freshMain->nomor_induk === null
                || (int) $freshMain->version !== $expectedVersion
            ) {
                throw new \RuntimeException('Revision create invariant violated: main changed.');
            }

            return $revision;
        });
    }

    /**
     * Submit revision Draft/Ditolak → Menunggu Tinjauan-REVISI + pending CANDIDATE_REVISION.
     *
     * @param  array{version?: int|string}  $options  revision.version
     */
    public function submitRevision(User $actor, int $revisionId, array $options = []): object
    {
        return DB::transaction(function () use ($actor, $revisionId, $options): object {
            $this->authorizeSubmit($actor);

            $revision = DB::table('candidate')->where('id', $revisionId)->first();
            if ($revision === null) {
                $this->fail('candidate', 'CANDIDATE_NOT_FOUND');
            }

            $this->assertSubmittableRevision($revision);

            $expectedVersion = $this->requireVersion($options);
            if ($expectedVersion !== (int) $revision->version) {
                throw new ConflictHttpException('CONFLICT');
            }

            $mainId = (int) $revision->parent_candidate_id;
            $main = DB::table('candidate')->where('id', $mainId)->first();
            if ($main === null) {
                $this->fail('candidate', 'CANDIDATE_MAIN_NOT_FOUND');
            }

            $this->assertRevisableMain($main);

            if ($this->hasActivePending($revisionId) || $this->hasActivePending($mainId)) {
                throw new ConflictHttpException('APV_DUPLICATE');
            }

            $revisionFingerprint = $this->aggregateFingerprint($revisionId);
            $mainFingerprint = $this->aggregateFingerprint($mainId);

            // All submits must differ from main (BR-APV-06 / BR-CAN).
            if ($revisionFingerprint === $mainFingerprint) {
                $this->fail('revision', 'CANDIDATE_NO_CHANGE');
            }

            // Resubmit from Ditolak: also must differ from last rejected submission fingerprint.
            if ((string) $revision->status_approval === CandidateApprovalStatus::Ditolak->value) {
                $lastRejectedFp = $this->lastRejectedFingerprint($revisionId);
                if ($lastRejectedFp !== null && $lastRejectedFp === $revisionFingerprint) {
                    $this->fail('revision', 'CANDIDATE_NO_CHANGE');
                }
            }

            $parentVersion = (int) $main->version;
            $newVersion = $expectedVersion + 1;

            try {
                $affected = DB::table('candidate')
                    ->where('id', $revisionId)
                    ->where('version', $expectedVersion)
                    ->whereIn('status_approval', [
                        CandidateApprovalStatus::Draft->value,
                        CandidateApprovalStatus::Ditolak->value,
                    ])
                    ->whereNull('nomor_induk')
                    ->whereNotNull('parent_candidate_id')
                    ->whereNull('deleted_at')
                    ->whereNull('pii_anonymized_at')
                    ->update([
                        'status_approval' => CandidateApprovalStatus::MenungguTinjauanRevisi->value,
                        'version' => $newVersion,
                        'updated_at' => now(),
                    ]);
            } catch (UniqueConstraintViolationException $exception) {
                if ($this->isActiveRevisionUniqueViolation($exception)) {
                    throw new ConflictHttpException('CANDIDATE_REVISION_ACTIVE', $exception);
                }

                throw $exception;
            }

            if ($affected !== 1) {
                throw new ConflictHttpException('CONFLICT');
            }

            $pendingPayload = [
                'parent_candidate_id' => $mainId,
                'parent_version' => $parentVersion,
                'parent_aggregate_fingerprint' => $mainFingerprint,
                'aggregate_fingerprint' => $revisionFingerprint,
            ];

            $pending = $this->pending->submit(
                type: PendingType::CANDIDATE_REVISION,
                targetType: 'candidate',
                targetId: $revisionId,
                requestedBy: (int) $actor->getKey(),
                auditAction: ActionType::CANDIDATE_REVISION_SUBMITTED,
                payload: $pendingPayload,
                auditDetail: [
                    'parent_candidate_id' => $mainId,
                    'parent_version' => $parentVersion,
                    'parent_aggregate_fingerprint' => $mainFingerprint,
                    'revision_id' => $revisionId,
                    'status_approval' => CandidateApprovalStatus::MenungguTinjauanRevisi->value,
                    'version' => $newVersion,
                    'aggregate_fingerprint' => $revisionFingerprint,
                ],
            );

            $approverIds = User::query()
                ->role(Rbac::CANDIDATE_APPROVER)
                ->where('status_akun', 'Aktif')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($approverIds !== []) {
                $notifyPayload = [
                    'candidate_id' => $mainId,
                    'revision_id' => $revisionId,
                    'pending_request_id' => $pending->getKey(),
                    'pending_type' => PendingType::CANDIDATE_REVISION->value,
                ];
                $this->notifications->notifyInApp(
                    $approverIds,
                    ActionType::CANDIDATE_REVISION_SUBMITTED->value,
                    $notifyPayload,
                );
                $this->notifications->queueEmailAfterCommit(
                    $approverIds,
                    ActionType::CANDIDATE_REVISION_SUBMITTED->value,
                    $notifyPayload,
                );
            }

            $fresh = $this->findOrFail($revisionId);
            if ($fresh->status_approval !== CandidateApprovalStatus::MenungguTinjauanRevisi->value
                || $fresh->nomor_induk !== null
            ) {
                throw new \RuntimeException('Revision submit invariant violated.');
            }

            $freshMain = $this->findOrFail($mainId);
            if ($freshMain->status_approval !== CandidateApprovalStatus::Disetujui->value
                || $freshMain->nomor_induk === null
            ) {
                throw new \RuntimeException('Revision submit invariant violated: main no longer approved.');
            }

            return $fresh;
        });
    }

    /**
     * Deterministic SHA-256 (64 hex) of canonical JSON aggregate.
     * Null ≠ empty string; omits id / candidate_id / timestamps.
     */
    public function aggregateFingerprint(int $candidateId): string
    {
        $row = DB::table('candidate')->where('id', $candidateId)->first();
        if ($row === null) {
            $this->fail('candidate', 'CANDIDATE_NOT_FOUND');
        }

        $main = [];
        foreach (self::MAIN_FIELDS as $field) {
            $main[$field] = $this->canonicalizeValue($row->{$field});
        }

        $children = [];
        foreach (self::CHILD_TABLES as $table) {
            $tableRows = DB::table($table)
                ->where('candidate_id', $candidateId)
                ->orderBy('id')
                ->get();

            $encoded = [];
            foreach ($tableRows as $child) {
                $data = (array) $child;
                unset($data['id'], $data['candidate_id'], $data['created_at'], $data['updated_at']);
                ksort($data);
                foreach ($data as $key => $value) {
                    $data[$key] = $this->canonicalizeValue($value);
                }
                $encoded[] = $data;
            }
            $children[$table] = $encoded;
        }

        $canonical = [
            'main' => $main,
            'children' => $children,
        ];

        return hash(
            'sha256',
            json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    public function hasOpenRevision(int $mainId): bool
    {
        return DB::table('candidate')
            ->where('parent_candidate_id', $mainId)
            ->whereIn('status_approval', [
                CandidateApprovalStatus::Draft->value,
                CandidateApprovalStatus::MenungguTinjauanRevisi->value,
                CandidateApprovalStatus::Ditolak->value,
            ])
            ->whereNull('deleted_at')
            ->exists();
    }

    public function hasActivePending(int $candidateId): bool
    {
        return DB::table('pending_request')
            ->where('target_type', 'candidate')
            ->where('target_id', $candidateId)
            ->where('status', 'pending')
            ->exists();
    }

    /**
     * Copy child rows from source candidate to destination (new ids).
     */
    public function cloneChildren(int $fromCandidateId, int $toCandidateId): void
    {
        $now = now();

        foreach (self::CHILD_TABLES as $table) {
            $rows = DB::table($table)->where('candidate_id', $fromCandidateId)->get();
            foreach ($rows as $row) {
                $data = (array) $row;
                unset($data['id']);
                $data['candidate_id'] = $toCandidateId;
                $data['created_at'] = $now;
                $data['updated_at'] = $now;
                DB::table($table)->insert($data);
            }
        }
    }

    /**
     * Replace destination children with a full copy of source children.
     */
    public function replaceChildrenFrom(int $fromCandidateId, int $toCandidateId): void
    {
        foreach (self::CHILD_TABLES as $table) {
            DB::table($table)->where('candidate_id', $toCandidateId)->delete();
        }

        $this->cloneChildren($fromCandidateId, $toCandidateId);
    }

    /**
     * Mutable field values for merge onto main (excludes system/immutable columns).
     *
     * @return array<string, mixed>
     */
    public function mutableSnapshot(object $revision): array
    {
        $out = [];
        foreach (self::MAIN_FIELDS as $field) {
            $out[$field] = $revision->{$field};
        }

        return $out;
    }

    private function lastRejectedFingerprint(int $revisionId): ?string
    {
        $row = DB::table('pending_request')
            ->where('type', PendingType::CANDIDATE_REVISION->value)
            ->where('target_type', 'candidate')
            ->where('target_id', $revisionId)
            ->where('status', PendingStatus::REJECTED->value)
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        $payload = $row->payload;
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (! is_array($payload)) {
            return null;
        }

        $fp = $payload['aggregate_fingerprint'] ?? null;
        if (! is_string($fp) || strlen($fp) !== 64 || ! ctype_xdigit($fp)) {
            return null;
        }

        return strtolower($fp);
    }

    /**
     * Preserve null vs empty string; dates as Y-m-d; no silent coalesce.
     */
    private function canonicalizeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            // Stable decimal encoding for NUMERIC columns.
            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') ?: '0';
        }

        if (is_string($value)) {
            // Date-looking strings stay as-is (already Y-m-d from PG); empty string stays "".
            return $value;
        }

        return $value;
    }

    private function authorizeCreate(User $actor): void
    {
        if ((int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException('CANDIDATE_ACTOR_MISMATCH');
        }

        Gate::forUser($actor)->authorize('candidate.update');
    }

    private function authorizeSubmit(User $actor): void
    {
        if ((int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException('CANDIDATE_ACTOR_MISMATCH');
        }

        Gate::forUser($actor)->authorize('candidate.submit');
    }

    private function assertRevisableMain(object $row): void
    {
        if ($row->pii_anonymized_at !== null || $row->deleted_at !== null) {
            $this->fail('candidate', 'CANDIDATE_NOT_REVISABLE');
        }

        if ($row->parent_candidate_id !== null) {
            $this->fail('candidate', 'CANDIDATE_NOT_MAIN');
        }

        if ($row->status_approval !== CandidateApprovalStatus::Disetujui->value) {
            $this->fail('status_approval', 'CANDIDATE_NOT_APPROVED');
        }

        if ($row->nomor_induk === null || trim((string) $row->nomor_induk) === '') {
            $this->fail('nomor_induk', 'CANDIDATE_NIK_REQUIRED');
        }
    }

    private function assertSubmittableRevision(object $row): void
    {
        if ($row->pii_anonymized_at !== null || $row->deleted_at !== null) {
            $this->fail('candidate', 'CANDIDATE_NOT_SUBMITTABLE');
        }

        if ($row->parent_candidate_id === null) {
            $this->fail('candidate', 'CANDIDATE_NOT_REVISION');
        }

        $status = (string) $row->status_approval;
        if ($status !== CandidateApprovalStatus::Draft->value
            && $status !== CandidateApprovalStatus::Ditolak->value
        ) {
            $this->fail('status_approval', 'CANDIDATE_NOT_REVISION_EDITABLE');
        }

        if ($row->nomor_induk !== null) {
            $this->fail('nomor_induk', 'CANDIDATE_NIK_ALREADY_SET');
        }
    }

    private function assertRevisionDraftGate(object $row): void
    {
        if ($row->status_approval !== CandidateApprovalStatus::Draft->value
            || $row->nomor_induk !== null
            || $row->parent_candidate_id === null
            || $this->hasActivePending((int) $row->id)
        ) {
            throw new \RuntimeException('Revision draft invariant violated: NIK, pending, or parent.');
        }
    }

    /**
     * @param  array{version?: int|string}  $options
     */
    private function requireVersion(array $options): int
    {
        $expectedVersion = $options['version'] ?? null;
        if (! is_int($expectedVersion) && ! (is_string($expectedVersion) && ctype_digit((string) $expectedVersion))) {
            $this->fail('version', 'CANDIDATE_VERSION_REQUIRED');
        }

        return (int) $expectedVersion;
    }

    private function isActiveRevisionUniqueViolation(UniqueConstraintViolationException $exception): bool
    {
        return str_contains($exception->getMessage(), 'uq_candidate_one_active_revision');
    }

    private function findOrFail(int $id): object
    {
        $row = DB::table('candidate')->where('id', $id)->first();
        if ($row === null) {
            $this->fail('candidate', 'CANDIDATE_NOT_FOUND');
        }

        return $row;
    }

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code]);
    }
}
