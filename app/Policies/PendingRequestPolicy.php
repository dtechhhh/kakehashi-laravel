<?php

namespace App\Policies;

use App\Models\User;
use Shared\Approval\MakerCheckerGate;
use Shared\Approval\PendingRequest;
use Shared\Approval\PendingStatus;

/**
 * Sinyal otorisasi untuk permukaan UI/route (SECURITY_CHECKLIST §4).
 *
 * Policy ini BUKAN penjaga terakhir: PendingRequestService memanggil
 * MakerCheckerGate yang sama di dalam transaksi keputusan, sehingga pemanggil
 * yang melewatkan `authorize()` tetap ditolak server-side.
 */
class PendingRequestPolicy
{
    public function __construct(private readonly MakerCheckerGate $gate) {}

    /**
     * Boleh menampilkan/menjalankan aksi setuju-tolak?
     *
     * Status ikut diperiksa agar UI tidak menawarkan aksi atas request yang
     * sudah diputus; keputusan kedua tetap dijawab 409 oleh service (BR-APV-07).
     */
    public function decide(User $actor, PendingRequest $request): bool
    {
        return $request->status === PendingStatus::PENDING
            && $this->gate->allows($request, (int) $actor->getKey());
    }
}
