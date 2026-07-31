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
use Modules\Candidates\Exceptions\SimilarityConfirmationRequired;
use Shared\Approval\PendingRequestService;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;
use Shared\Notifications\NotificationService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * W3-T3 — first submit: similarity soft-warn, NIK (JST), status BARU, pending CANDIDATE_NEW.
 *
 * Disetujui revision submit is CandidateRevisionService (W3-T5).
 */
final class CandidateSubmitService
{
    private const SIMILARITY_THRESHOLD = 0.4;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PendingRequestService $pending,
        private readonly NotificationService $notifications,
        private readonly CandidateRevisionService $revisions,
    ) {}

    /**
     * @param  array{version?: int|string, confirm_similarity?: bool}  $options
     */
    public function submit(User $actor, int $candidateId, array $options = []): object
    {
        return DB::transaction(function () use ($actor, $candidateId, $options): object {
            $this->authorizeSubmit($actor);

            // BR-CON-01/03: optimistic read only — no SELECT FOR UPDATE on candidate submit.
            $row = DB::table('candidate')->where('id', $candidateId)->first();
            if ($row === null) {
                $this->fail('candidate', 'CANDIDATE_NOT_FOUND');
            }

            $this->assertSubmittableDraft($row);

            $expectedVersion = $options['version'] ?? null;
            if (! is_int($expectedVersion) && ! (is_string($expectedVersion) && ctype_digit((string) $expectedVersion))) {
                $this->fail('version', 'CANDIDATE_VERSION_REQUIRED');
            }
            $expectedVersion = (int) $expectedVersion;
            if ($expectedVersion !== (int) $row->version) {
                throw new ConflictHttpException('CONFLICT');
            }

            if ($this->hasActivePending($candidateId)) {
                throw new ConflictHttpException('APV_DUPLICATE');
            }

            $this->assertSubmitReady($row);

            $matches = $this->findSimilarMatches($row);
            $confirm = filter_var($options['confirm_similarity'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($matches !== [] && ! $confirm) {
                throw new SimilarityConfirmationRequired($matches);
            }

            if ($matches !== []) {
                $this->audit->record(
                    actionType: ActionType::SIMILARITY_MATCH_SHOWN,
                    entityType: 'candidate',
                    entityId: $candidateId,
                    detail: [
                        'candidate_draft_id' => $candidateId,
                        'matches' => array_map(
                            static fn (array $m): array => [
                                'candidate_id' => $m['candidate_id'],
                                'score' => $m['score'],
                            ],
                            $matches,
                        ),
                        'threshold' => self::SIMILARITY_THRESHOLD,
                    ],
                    actorId: $actor->getKey(),
                );
            }

            $year = (int) now('Asia/Tokyo')->format('Y');
            $nomorInduk = $this->allocateNik($year);

            $newVersion = $expectedVersion + 1;

            try {
                $affected = DB::table('candidate')
                    ->where('id', $candidateId)
                    ->where('version', $expectedVersion)
                    ->where('status_approval', CandidateApprovalStatus::Draft->value)
                    ->whereNull('nomor_induk')
                    ->whereNull('deleted_at')
                    ->whereNull('pii_anonymized_at')
                    ->update([
                        'nomor_induk' => $nomorInduk,
                        'status_approval' => CandidateApprovalStatus::MenungguTinjauanBaru->value,
                        'version' => $newVersion,
                        'updated_at' => now(),
                    ]);
            } catch (UniqueConstraintViolationException $exception) {
                if ($this->isNomorIndukUniqueViolation($exception)) {
                    throw new ConflictHttpException('NIK_DUP', $exception);
                }

                throw $exception;
            }

            if ($affected !== 1) {
                throw new ConflictHttpException('CONFLICT');
            }

            $fingerprint = $this->revisions->aggregateFingerprint($candidateId);

            $pending = $this->pending->submit(
                type: PendingType::CANDIDATE_NEW,
                targetType: 'candidate',
                targetId: $candidateId,
                requestedBy: (int) $actor->getKey(),
                auditAction: ActionType::CANDIDATE_SUBMITTED,
                payload: [
                    'aggregate_fingerprint' => $fingerprint,
                ],
                auditDetail: [
                    'nomor_induk' => $nomorInduk,
                    'status_approval' => CandidateApprovalStatus::MenungguTinjauanBaru->value,
                    'version' => $newVersion,
                    'jst_year' => $year,
                ],
            );

            $approverIds = User::query()
                ->role(Rbac::CANDIDATE_APPROVER)
                ->where('status_akun', 'Aktif')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($approverIds !== []) {
                $payload = [
                    'candidate_id' => $candidateId,
                    'pending_request_id' => $pending->getKey(),
                    'pending_type' => PendingType::CANDIDATE_NEW->value,
                ];
                $this->notifications->notifyInApp(
                    $approverIds,
                    ActionType::CANDIDATE_SUBMITTED->value,
                    $payload,
                );
                $this->notifications->queueEmailAfterCommit(
                    $approverIds,
                    ActionType::CANDIDATE_SUBMITTED->value,
                    $payload,
                );
            }

            $fresh = DB::table('candidate')->where('id', $candidateId)->first();
            if ($fresh === null) {
                $this->fail('candidate', 'CANDIDATE_NOT_FOUND');
            }

            if ($fresh->nomor_induk === null
                || $fresh->status_approval !== CandidateApprovalStatus::MenungguTinjauanBaru->value) {
                throw new \RuntimeException('Submit invariant violated: NIK or status missing after commit path.');
            }

            return $fresh;
        });
    }

    /**
     * @return list<array{candidate_id: int, nomor_induk: ?string, score: float}>
     */
    public function findSimilarMatches(object $row): array
    {
        $latin = $this->normalizeLatin((string) $row->nama_alphabet);
        $kana = $this->normalizeKana($row->nama_katakana !== null ? (string) $row->nama_katakana : null);
        $kanaForSql = $kana ?? '';

        $sql = <<<'SQL'
            SELECT
                id,
                nomor_induk,
                GREATEST(
                    similarity(lower(nama_alphabet), lower(?)),
                    CASE
                        WHEN nama_katakana IS NOT NULL AND ? <> ''
                            THEN similarity(nama_katakana, ?)
                        ELSE 0
                    END
                ) AS score
            FROM candidate
            WHERE id <> ?
              AND tanggal_lahir = ?
              AND kewarganegaraan_id = ?
              AND pii_anonymized_at IS NULL
              AND deleted_at IS NULL
              AND (
                    similarity(lower(nama_alphabet), lower(?)) >= ?
                 OR (
                        nama_katakana IS NOT NULL
                    AND ? <> ''
                    AND similarity(nama_katakana, ?) >= ?
                 )
              )
            ORDER BY score DESC
            LIMIT 25
            SQL;

        $bindings = [
            $latin,
            $kanaForSql,
            $kanaForSql,
            (int) $row->id,
            (string) $row->tanggal_lahir,
            (int) $row->kewarganegaraan_id,
            $latin,
            self::SIMILARITY_THRESHOLD,
            $kanaForSql,
            $kanaForSql,
            self::SIMILARITY_THRESHOLD,
        ];

        $rows = DB::select($sql, $bindings);

        return array_map(static function (object $match): array {
            return [
                'candidate_id' => (int) $match->id,
                'nomor_induk' => $match->nomor_induk !== null ? (string) $match->nomor_induk : null,
                'score' => round((float) $match->score, 4),
            ];
        }, $rows);
    }

    public function hasActivePending(int $candidateId): bool
    {
        return DB::table('pending_request')
            ->where('target_type', 'candidate')
            ->where('target_id', $candidateId)
            ->where('status', 'pending')
            ->exists();
    }

    private function authorizeSubmit(User $actor): void
    {
        if ((int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException('CANDIDATE_ACTOR_MISMATCH');
        }

        Gate::forUser($actor)->authorize('candidate.submit');
    }

    private function assertSubmittableDraft(object $row): void
    {
        if ($row->pii_anonymized_at !== null || $row->deleted_at !== null) {
            $this->fail('candidate', 'CANDIDATE_NOT_SUBMITTABLE');
        }

        if ($row->parent_candidate_id !== null) {
            $this->fail('candidate', 'CANDIDATE_NOT_MAIN');
        }

        if ($row->status_approval !== CandidateApprovalStatus::Draft->value) {
            $this->fail('status_approval', 'CANDIDATE_NOT_DRAFT');
        }

        if ($row->nomor_induk !== null) {
            $this->fail('nomor_induk', 'CANDIDATE_NIK_ALREADY_SET');
        }
    }

    private function assertSubmitReady(object $row): void
    {
        $nama = trim((string) $row->nama_alphabet);
        if ($nama === '') {
            $this->fail('nama_alphabet', 'CANDIDATE_FIELD_REQUIRED');
        }

        if ($row->tanggal_lahir === null || (string) $row->tanggal_lahir === '') {
            $this->fail('tanggal_lahir', 'CANDIDATE_FIELD_REQUIRED');
        }

        if ($row->kewarganegaraan_id === null) {
            $this->fail('kewarganegaraan_id', 'CANDIDATE_FIELD_REQUIRED');
        }

        if ($row->jenis_kelamin === null || trim((string) $row->jenis_kelamin) === '') {
            $this->fail('jenis_kelamin', 'CANDIDATE_FIELD_REQUIRED');
        }
    }

    private function allocateNik(int $year): string
    {
        $result = DB::selectOne(
            <<<'SQL'
                INSERT INTO nik_counter (year, last_value, updated_at)
                VALUES (?, 1, NOW())
                ON CONFLICT (year) DO UPDATE
                SET last_value = nik_counter.last_value + 1,
                    updated_at = NOW()
                RETURNING last_value
                SQL,
            [$year],
        );

        $seq = (int) $result->last_value;
        if ($seq > 99999) {
            $this->fail('nomor_induk', 'NIK_OVERFLOW');
        }

        return sprintf('K-%d-%05d', $year, $seq);
    }

    private function isNomorIndukUniqueViolation(UniqueConstraintViolationException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'candidate_nomor_induk_unique')
            || str_contains($message, 'candidate_nomor_induk_key');
    }

    private function normalizeLatin(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name) ?? $name;

        return trim($name);
    }

    private function normalizeKana(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return $name;
    }

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code]);
    }
}
