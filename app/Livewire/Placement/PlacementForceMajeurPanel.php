<?php

namespace App\Livewire\Placement;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\LookupData\Public\LookupService;
use Modules\Placement\Public\PlacementQueryService;
use Modules\Placement\Services\PlacementForceMajeurService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * UI-W5-T6 — P5 Force-Majeur request (Maker, approval rutin tanpa step-up).
 *
 * Candidate picker = Tersedia + Disetujui. Kategori lookup + alasan free-text
 * keduanya wajib (CHECK DB); source_participation_id NULL. Submit membuat
 * pending FORCE_MAJEUR; kandidat tetap Tersedia sampai Checker approve.
 */
final class PlacementForceMajeurPanel extends Component
{
    public int $containerId;

    public int $version;

    public string $search = '';

    public ?int $candidateId = null;

    public string $kategoriId = '';

    public string $alasan = '';

    public string $visaId = '';

    public string $tanggalMulai = '';

    public string $durasi = '12';

    public string $tanggalBerakhir = '';

    public ?string $actionError = null;

    public bool $conflict = false;

    public function mount(int $containerId, int $version): void
    {
        $this->containerId = $containerId;
        $this->version = $version;
    }

    public function render()
    {
        Gate::authorize('placement.execute');

        return view('livewire.placement.placement-force-majeur-panel', [
            'candidates' => app(PlacementQueryService::class)->eligibleForceMajeurCandidates(Auth::user(), $this->search),
            'kategoriOptions' => app(LookupService::class)->optionsById('kategori_force_majeur', app()->getLocale()),
            'visaOptions' => app(LookupService::class)->optionsById('jenis_visa', app()->getLocale()),
        ]);
    }

    public function selectCandidate(int $candidateId): void
    {
        $this->candidateId = $this->candidateId === $candidateId ? null : $candidateId;
        $this->actionError = null;
        $this->conflict = false;
    }

    public function submit(): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if (trim($this->alasan) === '') {
            $this->actionError = __('ui.placement.force_majeur.reason_required');

            return;
        }

        if ($this->candidateId === null || $this->kategoriId === '' || $this->visaId === '' || $this->tanggalMulai === '') {
            $this->actionError = __('ui.placement.force_majeur.incomplete');

            return;
        }

        try {
            app(PlacementForceMajeurService::class)
                ->requestForceMajeur(Auth::user(), $this->containerId, [
                    'candidate_id' => $this->candidateId,
                    'kategori_force_majeur_id' => (int) $this->kategoriId,
                    'alasan_force_majeur' => trim($this->alasan),
                    'jenis_visa_id' => (int) $this->visaId,
                    'tanggal_mulai_kerja' => $this->tanggalMulai,
                    'durasi_kontrak_bulan' => max(1, (int) $this->durasi),
                    'tanggal_berakhir_kontrak' => $this->tanggalBerakhir !== '' ? $this->tanggalBerakhir : null,
                ], ['version' => $this->version]);

            $this->candidateId = null;
            $this->kategoriId = '';
            $this->alasan = '';
            $this->tanggalMulai = '';
            $this->durasi = '12';
            $this->tanggalBerakhir = '';
            session()->flash('status', __('ui.placement.force_majeur.requested'));
            $this->redirect(route('placements.show', $this->containerId));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (AuthorizationException|AccessDeniedHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        }
    }

    private function firstError(ValidationException $exception): string
    {
        $message = (string) collect($exception->errors())->flatten()->first();
        $key = 'ui.placement.errors.'.$message;
        $translated = __($key);

        return $translated === $key ? $message : $translated;
    }

    private function translateCode(string $code): string
    {
        $key = 'ui.placement.errors.'.$code;
        $translated = __($key);

        return $translated === $key ? $code : $translated;
    }
}
