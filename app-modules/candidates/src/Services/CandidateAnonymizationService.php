<?php

namespace Modules\Candidates\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Public\StepUpService;
use Modules\Auth\Rbac;
use Modules\Auth\StepUpAction;
use Modules\Jobs\Public\InterviewQueryService;
use Modules\Placement\Public\PlacementQueryService;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;

/**
 * W7-T2/T3 — formal PII anonymization (PRD §7.9, MODULE_CANDIDATES §9.1).
 *
 * Super Admin + step-up only; Wave 3 eligibility guards are revalidated
 * inside the transaction (candidate row + revisions locked); tombstone
 * pii_anonymized_at is permanent. Photo deletion is after-commit and
 * best-effort: a file failure never rolls back the business tombstone.
 */
final class CandidateAnonymizationService
{
    private const SCRAMBLED_NAME = 'ANONIM';

    private const SCRAMBLED_BIRTH_DATE = '1970-01-01';

    /** All PII-bearing child tables are removed with the tombstone. */
    private const CHILD_TABLES = [
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
        private readonly CandidateAnonymizationEligibilityService $eligibility,
        private readonly InterviewQueryService $interview,
        private readonly PlacementQueryService $placement,
        private readonly StepUpService $stepUp,
        private readonly AuditLogger $audit,
        private readonly CandidatePhotoService $photos,
    ) {}

    public function anonymize(User $actor, int $candidateId): void
    {
        $this->assertSuperAdmin($actor);

        $this->eligibility->run(
            $candidateId,
            fn (int $id): bool => $this->interview->hasActiveParticipation($id),
            fn (int $id): bool => $this->placement->hasWorkingPlacement($id),
            fn (int $id): bool => DB::table('pending_request')
                ->where('target_type', 'candidate')
                ->where('target_id', $id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->exists(),
            function (object $candidate) use ($actor): void {
                $this->stepUp->require(
                    StepUpAction::ANONYMIZE_PII,
                    'candidate',
                    (int) $candidate->id,
                );
                $this->applyTombstone($actor, $candidate);
            },
        );
    }

    private function assertSuperAdmin(User $actor): void
    {
        $allowed = (int) Auth::id() === (int) $actor->getKey()
            && $actor->status_akun === 'Aktif'
            && $actor->hasRole(Rbac::SUPER_ADMIN)
            && $actor->hasPermissionTo('candidate.anonymize');

        if (! $allowed) {
            throw new AuthorizationException('CANDIDATE_ANONYMIZE_FORBIDDEN');
        }
    }

    private function applyTombstone(User $actor, object $candidate): void
    {
        $candidateId = (int) $candidate->id;
        $photoKey = DB::table('candidate_photo')
            ->where('candidate_id', $candidateId)
            ->value('object_key');

        foreach (self::CHILD_TABLES as $table) {
            DB::table($table)->where('candidate_id', $candidateId)->delete();
        }

        DB::table('candidate')
            ->where('id', $candidateId)
            ->update([
                'nama_alphabet' => self::SCRAMBLED_NAME,
                'nama_katakana' => null,
                'tanggal_lahir' => self::SCRAMBLED_BIRTH_DATE,
                'tempat_lahir_kota_id' => null,
                'alamat_detail' => null,
                'email' => null,
                'phone' => null,
                'line_id' => null,
                'alamat_provinsi_id' => null,
                'alamat_kota_kabupaten_id' => null,
                'alamat_kecamatan_id' => null,
                'status_pernikahan' => null,
                'catatan_penolakan_terakhir' => null,
                'catatan_tambahan' => null,
                'pii_anonymized_at' => now(),
                'updated_at' => now(),
            ]);

        $this->audit->record(
            actionType: ActionType::CANDIDATE_ANONYMIZED,
            entityType: 'candidate',
            entityId: $candidateId,
            detail: [
                'candidate_id' => $candidateId,
                'nomor_induk' => $candidate->nomor_induk,
            ],
            actorId: $actor->getKey(),
        );

        if (is_string($photoKey) && $photoKey !== '') {
            $this->photos->scheduleDeleteIfUnreferenced($photoKey);
        }
    }
}
