<?php

namespace Shared\Approval;

use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Gate Maker-Checker (PRD §7.4, BR-APV-01/02) — satu-satunya tempat kewenangan
 * memutus `pending_request` diputuskan.
 *
 * Domain TIDAK boleh menyalin aturan ini: PendingRequestService memanggil gate
 * di dalam transaksi keputusan, dan PendingRequestPolicy memanggil gate yang
 * sama untuk sinyal UI. Satu aturan, dua permukaan — tombol yang disembunyikan
 * UI tidak pernah menjadi satu-satunya penjaga (SECURITY_CHECKLIST §4).
 *
 * Kedua penolakan adalah penolakan akses, karena itu 403 (BUSINESS_RULES §0).
 */
final class MakerCheckerGate
{
    private const ACTIVE = 'Aktif';

    /**
     * BR-APV-02 — kewenangan approval per entitas sasaran:
     * Kandidat → Approver Kandidat; Wawancara & Penempatan → Manajer Job.
     *
     * Peta ini memakai permission (bukan nama peran) agar sejalan dengan
     * Rbac::ROLE_PERMISSIONS; MakerCheckerGateTest menjaga keduanya tidak
     * melenceng dan memaksa setiap PendingType baru dipetakan.
     *
     * Super Admin, Staf Input, Asisten Manajer, dan Tamu tidak memegang satu pun
     * permission di bawah, sehingga gugur tanpa perlu daftar larangan terpisah
     * (ROLES §7 — Super Admin tidak pernah menjadi Checker aksi operasional).
     */
    public static function checkerPermission(PendingType $type): string
    {
        return match ($type) {
            PendingType::CANDIDATE_NEW,
            PendingType::CANDIDATE_REVISION => 'candidate.review',

            PendingType::IC_CREATE,
            PendingType::IC_CLOSE,
            PendingType::IC_EXPEL,
            PendingType::GUEST_LINK => 'jobs.review',

            PendingType::PC_CREATE,
            PendingType::PC_CANCEL_ACTIVE,
            PendingType::PLACEMENT_BATCH,
            PendingType::PLACEMENT_RESIGN,
            PendingType::PLACEMENT_EXPEL,
            PendingType::FORCE_MAJEUR => 'placement.review',
        };
    }

    /**
     * @return User checker yang tervalidasi (dipakai pemanggil untuk audit)
     *
     * @throws AccessDeniedHttpException APV_SELF / APV_ROLE (403)
     */
    public function assertCanDecide(PendingRequest $request, int $checkerId): User
    {
        // BR-APV-01 diperiksa lebih dahulu: Maker yang kebetulan memegang
        // permission Checker tetap harus ditolak sebagai APV_SELF, bukan
        // lolos hanya karena perannya benar.
        if ($request->requested_by === $checkerId) {
            $this->deny('APV_SELF');
        }

        $checker = User::query()->find($checkerId);

        // Akun Nonaktif tidak punya kewenangan efektif, jadi dipetakan ke
        // APV_ROLE alih-alih memperkenalkan kode pesan baru di luar authority.
        if ($checker === null
            || $checker->status_akun !== self::ACTIVE
            || ! $checker->checkPermissionTo(self::checkerPermission($request->type))
        ) {
            $this->deny('APV_ROLE');
        }

        return $checker;
    }

    /**
     * Varian boolean untuk Policy/UI. Hanya menelan penolakan otorisasi.
     */
    public function allows(PendingRequest $request, int $checkerId): bool
    {
        try {
            $this->assertCanDecide($request, $checkerId);

            return true;
        } catch (AccessDeniedHttpException) {
            return false;
        }
    }

    private function deny(string $code): never
    {
        throw new AccessDeniedHttpException($code);
    }
}
