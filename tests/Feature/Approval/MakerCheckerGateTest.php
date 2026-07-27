<?php

namespace Tests\Feature\Approval;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Auth\Rbac;
use Shared\Approval\MakerCheckerGate;
use Shared\Approval\PendingRequest;
use Shared\Approval\PendingRequestService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * W1-T6 — gate Maker-Checker (BR-APV-01/02) yang WAJIB dipakai semua domain.
 *
 * Fokus kelas ini: siapa yang boleh memutus. Revalidasi in-transaction,
 * anti double-approval, dan note wajib tetap milik PendingRequestServiceTest.
 */
class MakerCheckerGateTest extends TestCase
{
    use RefreshDatabase;

    private PendingRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->service = app(PendingRequestService::class);
    }

    /**
     * BR-APV-02 — Kandidat → Approver Kandidat; Wawancara & Penempatan →
     * Manajer Job. Jalur sah harus benar-benar memutus, bukan sekadar lolos.
     */
    public function test_each_authority_family_is_decided_by_its_mapped_checker(): void
    {
        $cases = [
            [PendingType::CANDIDATE_NEW, 'candidate', 101, Rbac::STAFF_INPUT, Rbac::CANDIDATE_APPROVER, ActionType::CANDIDATE_SUBMITTED, ActionType::CANDIDATE_APPROVED],
            [PendingType::IC_CREATE, 'interview_container', 102, Rbac::ASSISTANT_MANAGER, Rbac::JOB_MANAGER, ActionType::IC_SUBMITTED, ActionType::IC_APPROVED],
            [PendingType::PLACEMENT_BATCH, 'placement_container', 103, Rbac::ASSISTANT_MANAGER, Rbac::JOB_MANAGER, ActionType::PC_SUBMITTED, ActionType::BATCH_SENT],
        ];

        foreach ($cases as [$type, $targetType, $targetId, $makerRole, $checkerRole, $submitAction, $approveAction]) {
            $maker = $this->userWithRole($makerRole);
            $checker = $this->userWithRole($checkerRole);

            $request = $this->service->submit(
                type: $type,
                targetType: $targetType,
                targetId: $targetId,
                requestedBy: $maker->getKey(),
                auditAction: $submitAction,
                payload: $type->requiresPayload() ? ['snapshot' => [1, 2]] : null,
            );

            $approved = $this->service->approve(
                requestId: $request->getKey(),
                checkerId: $checker->getKey(),
                auditAction: $approveAction,
            );

            $this->assertSame(PendingStatus::APPROVED, $approved->status, $type->value);
            $this->assertSame($checker->getKey(), $approved->checker_id, $type->value);
        }
    }

    /**
     * Gate task W1-T6: penolakan datang dari BR-APV-01, bukan efek samping
     * pemisahan peran. Maker di sini MEMEGANG permission Checker yang benar
     * dan tetap ditolak untuk request buatannya sendiri.
     */
    public function test_maker_holding_the_checker_permission_still_cannot_self_approve(): void
    {
        $maker = $this->userWithRole(Rbac::JOB_MANAGER);

        $this->assertTrue(
            $maker->checkPermissionTo('jobs.review'),
            'prasyarat test: maker sengaja memegang permission Checker'
        );

        $request = $this->service->submit(
            type: PendingType::IC_CREATE,
            targetType: 'interview_container',
            targetId: 111,
            requestedBy: $maker->getKey(),
            auditAction: ActionType::IC_SUBMITTED,
        );

        $this->assertDenied('APV_SELF', $request, fn () => $this->service->approve(
            requestId: $request->getKey(),
            checkerId: $maker->getKey(),
            auditAction: ActionType::IC_APPROVED,
        ));

        $this->assertDenied('APV_SELF', $request, fn () => $this->service->reject(
            requestId: $request->getKey(),
            checkerId: $maker->getKey(),
            note: 'saya batalkan sendiri',
            auditAction: ActionType::IC_REJECTED,
        ));

        // Checker lain yang sah tetap bisa memutus request yang sama.
        $checker = $this->userWithRole(Rbac::JOB_MANAGER);

        $this->assertSame(
            PendingStatus::APPROVED,
            $this->service->approve(
                requestId: $request->getKey(),
                checkerId: $checker->getKey(),
                auditAction: ActionType::IC_APPROVED,
            )->status
        );
    }

    /**
     * Maker tanpa permission Checker harus tetap dijawab APV_SELF — urutan gate
     * memeriksa BR-APV-01 lebih dahulu agar pesan tidak menyesatkan.
     */
    public function test_self_check_precedes_role_check(): void
    {
        $maker = $this->userWithRole(Rbac::ASSISTANT_MANAGER);

        $request = $this->service->submit(
            type: PendingType::IC_CREATE,
            targetType: 'interview_container',
            targetId: 112,
            requestedBy: $maker->getKey(),
            auditAction: ActionType::IC_SUBMITTED,
        );

        $this->assertFalse($maker->checkPermissionTo('jobs.review'));

        $this->assertDenied('APV_SELF', $request, fn () => $this->service->approve(
            requestId: $request->getKey(),
            checkerId: $maker->getKey(),
            auditAction: ActionType::IC_APPROVED,
        ));
    }

    /**
     * BR-APV-02 — Checker keluarga lain ditolak APV_ROLE.
     */
    public function test_checker_from_another_authority_family_is_denied(): void
    {
        $candidateRequest = $this->pendingOfType(PendingType::CANDIDATE_NEW, 'candidate', 121, Rbac::STAFF_INPUT, ActionType::CANDIDATE_SUBMITTED);
        $interviewRequest = $this->pendingOfType(PendingType::IC_CREATE, 'interview_container', 122, Rbac::ASSISTANT_MANAGER, ActionType::IC_SUBMITTED);

        $jobManager = $this->userWithRole(Rbac::JOB_MANAGER);
        $candidateApprover = $this->userWithRole(Rbac::CANDIDATE_APPROVER);

        $this->assertDenied('APV_ROLE', $candidateRequest, fn () => $this->service->approve(
            requestId: $candidateRequest->getKey(),
            checkerId: $jobManager->getKey(),
            auditAction: ActionType::CANDIDATE_APPROVED,
        ));

        $this->assertDenied('APV_ROLE', $interviewRequest, fn () => $this->service->approve(
            requestId: $interviewRequest->getKey(),
            checkerId: $candidateApprover->getKey(),
            auditAction: ActionType::IC_APPROVED,
        ));
    }

    /**
     * Peran Maker, Super Admin (ROLES §7 — read-only, tidak pernah Checker),
     * dan user tanpa peran semuanya gugur.
     */
    public function test_maker_roles_super_admin_and_roleless_users_cannot_decide(): void
    {
        $request = $this->pendingOfType(PendingType::IC_CREATE, 'interview_container', 131, Rbac::ASSISTANT_MANAGER, ActionType::IC_SUBMITTED);

        $outsiders = [
            'staf input' => $this->userWithRole(Rbac::STAFF_INPUT),
            'asisten manajer lain' => $this->userWithRole(Rbac::ASSISTANT_MANAGER),
            'approver kandidat' => $this->userWithRole(Rbac::CANDIDATE_APPROVER),
            'super admin' => $this->userWithRole(Rbac::SUPER_ADMIN),
            'tanpa peran' => User::factory()->active()->create(),
        ];

        foreach ($outsiders as $label => $actor) {
            $this->assertDenied('APV_ROLE', $request, fn () => $this->service->approve(
                requestId: $request->getKey(),
                checkerId: $actor->getKey(),
                auditAction: ActionType::IC_APPROVED,
            ), $label);

            $this->assertDenied('APV_ROLE', $request, fn () => $this->service->reject(
                requestId: $request->getKey(),
                checkerId: $actor->getKey(),
                note: 'coba tolak',
                auditAction: ActionType::IC_REJECTED,
            ), $label.' (reject)');
        }
    }

    public function test_unknown_checker_id_is_denied(): void
    {
        $request = $this->pendingOfType(PendingType::IC_CREATE, 'interview_container', 141, Rbac::ASSISTANT_MANAGER, ActionType::IC_SUBMITTED);

        $this->assertDenied('APV_ROLE', $request, fn () => $this->service->approve(
            requestId: $request->getKey(),
            checkerId: 987654321,
            auditAction: ActionType::IC_APPROVED,
        ));
    }

    public function test_inactive_checker_with_the_correct_role_is_denied(): void
    {
        $request = $this->pendingOfType(PendingType::IC_CREATE, 'interview_container', 142, Rbac::ASSISTANT_MANAGER, ActionType::IC_SUBMITTED);

        $checker = $this->userWithRole(Rbac::JOB_MANAGER);
        $checker->forceFill(['status_akun' => 'Nonaktif'])->save();

        $this->assertTrue($checker->fresh()->checkPermissionTo('jobs.review'), 'peran tetap melekat; yang gugur adalah akunnya');

        $this->assertDenied('APV_ROLE', $request, fn () => $this->service->approve(
            requestId: $request->getKey(),
            checkerId: $checker->getKey(),
            auditAction: ActionType::IC_APPROVED,
        ));
    }

    /**
     * Gate mendahului revalidasi status: aktor tak berwenang tidak boleh
     * mengetahui bahwa request sudah diputus aktor lain.
     */
    public function test_unauthorized_actor_gets_403_not_409_on_a_decided_request(): void
    {
        $request = $this->pendingOfType(PendingType::IC_CREATE, 'interview_container', 151, Rbac::ASSISTANT_MANAGER, ActionType::IC_SUBMITTED);

        $this->service->approve(
            requestId: $request->getKey(),
            checkerId: $this->userWithRole(Rbac::JOB_MANAGER)->getKey(),
            auditAction: ActionType::IC_APPROVED,
        );

        $outsider = $this->userWithRole(Rbac::STAFF_INPUT);

        try {
            $this->service->approve(
                requestId: $request->getKey(),
                checkerId: $outsider->getKey(),
                auditAction: ActionType::IC_APPROVED,
            );
            $this->fail('unauthorized actor must be denied');
        } catch (AccessDeniedHttpException $e) {
            $this->assertSame('APV_ROLE', $e->getMessage());
            $this->assertSame(403, $e->getStatusCode());
        }

        // Checker sah yang datang terlambat tetap mendapat 409 (BR-APV-07).
        try {
            $this->service->approve(
                requestId: $request->getKey(),
                checkerId: $this->userWithRole(Rbac::JOB_MANAGER)->getKey(),
                auditAction: ActionType::IC_APPROVED,
            );
            $this->fail('second valid decision must conflict');
        } catch (ConflictHttpException $e) {
            $this->assertSame('APV_DONE', $e->getMessage());
        }
    }

    /**
     * Policy (sinyal UI) dan service (penjaga server) memakai gate yang sama.
     */
    public function test_policy_matches_the_service_for_every_actor(): void
    {
        $maker = $this->userWithRole(Rbac::ASSISTANT_MANAGER);

        $request = $this->service->submit(
            type: PendingType::IC_CREATE,
            targetType: 'interview_container',
            targetId: 161,
            requestedBy: $maker->getKey(),
            auditAction: ActionType::IC_SUBMITTED,
        );

        $allowed = $this->userWithRole(Rbac::JOB_MANAGER);

        $denied = [
            'maker' => $maker,
            'staf input' => $this->userWithRole(Rbac::STAFF_INPUT),
            'approver kandidat' => $this->userWithRole(Rbac::CANDIDATE_APPROVER),
            'super admin' => $this->userWithRole(Rbac::SUPER_ADMIN),
            'tanpa peran' => User::factory()->active()->create(),
        ];

        $this->assertTrue(Gate::forUser($allowed)->allows('decide', $request));

        foreach ($denied as $label => $actor) {
            $this->assertFalse(Gate::forUser($actor)->allows('decide', $request), $label);
        }

        $this->service->approve(
            requestId: $request->getKey(),
            checkerId: $allowed->getKey(),
            auditAction: ActionType::IC_APPROVED,
        );

        $this->assertFalse(
            Gate::forUser($allowed)->allows('decide', $request->fresh()),
            'request yang sudah diputus tidak boleh menawarkan aksi'
        );
    }

    /**
     * Anti-drift: setiap PendingType wajib punya pemetaan kewenangan, dan
     * permission-nya wajib benar-benar ada di katalog RBAC.
     */
    public function test_every_pending_type_maps_to_a_permission_that_exists_in_rbac(): void
    {
        $catalog = Rbac::permissions();

        foreach (PendingType::cases() as $type) {
            $permission = MakerCheckerGate::checkerPermission($type);

            $this->assertContains($permission, $catalog, "PendingType {$type->value} dipetakan ke permission tak dikenal");
        }
    }

    /**
     * Setiap penolakan gate harus meninggalkan request utuh dan nol audit.
     *
     * @param  callable():mixed  $decision
     */
    private function assertDenied(string $code, PendingRequest $request, callable $decision, string $context = ''): void
    {
        $auditBefore = AuditLog::query()->count();

        try {
            $decision();
            $this->fail("decision must be denied with {$code} {$context}");
        } catch (AccessDeniedHttpException $e) {
            $this->assertSame($code, $e->getMessage(), $context);
            $this->assertSame(403, $e->getStatusCode(), $context);
        }

        $fresh = $request->fresh();

        $this->assertSame(PendingStatus::PENDING, $fresh->status, $context);
        $this->assertNull($fresh->checker_id, $context);
        $this->assertNull($fresh->note_checker, $context);
        $this->assertNull($fresh->decided_at, $context);
        $this->assertSame($auditBefore, AuditLog::query()->count(), "penolakan tidak boleh menulis audit {$context}");
    }

    private function pendingOfType(
        PendingType $type,
        string $targetType,
        int $targetId,
        string $makerRole,
        ActionType $submitAction,
    ): PendingRequest {
        return $this->service->submit(
            type: $type,
            targetType: $targetType,
            targetId: $targetId,
            requestedBy: $this->userWithRole($makerRole)->getKey(),
            auditAction: $submitAction,
            payload: $type->requiresPayload() ? ['snapshot' => [1]] : null,
        );
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole($role);

        return $user;
    }
}
