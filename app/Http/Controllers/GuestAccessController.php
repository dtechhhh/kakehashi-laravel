<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\GuestAccess\Exceptions\GuestAccessDeniedException;
use Modules\GuestAccess\Public\GuestCandidateReadModel;
use Modules\GuestAccess\Services\GuestAccessService;
use Modules\GuestAccess\Services\GuestPhotoService;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * W6-U2 — Guest surface (token gate, G2 list, G3 detail, scoped photo).
 * Read-only; scope always comes from the validated session.
 */
final class GuestAccessController extends Controller
{
    public function __construct(
        private readonly GuestAccessService $access,
        private readonly GuestCandidateReadModel $candidates,
        private readonly GuestPhotoService $photos,
    ) {}

    public function gate(string $token): Response|RedirectResponse
    {
        if ($this->access->requiresCode($token)) {
            return response()->view('guest.code', ['token' => $token]);
        }

        try {
            $this->access->enter($token, null);
        } catch (GuestAccessDeniedException $exception) {
            return $this->denied($exception);
        }

        return redirect()->route('guest.candidates');
    }

    public function submitCode(Request $request, string $token): Response|RedirectResponse
    {
        try {
            $this->access->enter($token, (string) $request->string('code'));
        } catch (GuestAccessDeniedException $exception) {
            return $this->denied($exception);
        }

        return redirect()->route('guest.candidates');
    }

    public function candidates(): Response|RedirectResponse
    {
        try {
            $session = $this->access->currentSession();
        } catch (GuestAccessDeniedException $exception) {
            return $this->denied($exception);
        }

        $list = $this->candidates->listForContainer(
            $session,
            $request = request()->only(['sort', 'direction', 'page']),
        );

        return response()->view('guest.candidates', [
            'list' => $list,
            'container' => $this->containerHeader($session->containerId),
        ]);
    }

    public function detail(int $candidate): Response|RedirectResponse
    {
        try {
            $session = $this->access->currentSession();
            $detail = $this->candidates->detailForGuest($session, $candidate);
        } catch (GuestAccessDeniedException $exception) {
            return $this->denied($exception);
        }

        return response()->view('guest.detail', [
            'detail' => $detail,
            'container' => $this->containerHeader($session->containerId),
        ]);
    }

    public function photo(int $candidate): RedirectResponse|Response
    {
        try {
            $session = $this->access->currentSession();
            $url = $this->photos->signedPhotoUrl($session, $candidate);
        } catch (GuestAccessDeniedException $exception) {
            return $this->denied($exception);
        }

        return redirect()->away($url);
    }

    private function denied(GuestAccessDeniedException $exception): Response
    {
        return response()->view('guest.denied', [], $exception->isThrottled ? 429 : 404);
    }

    /** @return array{nama_perusahaan: string, tanggal_wawancara: string, jenis_wawancara: string} */
    private function containerHeader(int $containerId): array
    {
        $container = DB::table('interview_container as ic')
            ->join('perusahaan as p', 'p.id', '=', 'ic.perusahaan_id')
            ->where('ic.id', $containerId)
            ->select('p.nama_ja', 'ic.tanggal_wawancara', 'ic.jenis_wawancara')
            ->first();

        return [
            'nama_perusahaan' => $container?->nama_ja ?? '',
            'tanggal_wawancara' => $container?->tanggal_wawancara ?? '',
            'jenis_wawancara' => $container?->jenis_wawancara ?? '',
        ];
    }
}
