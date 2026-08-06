<?php

namespace App\Livewire\Placement;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\LookupData\Public\LookupService;
use Modules\Placement\Public\PlacementQueryService;
use Modules\Placement\Services\PlacementBatchService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * UI-W5-T4 — P4 batch normal submit (Maker, no step-up).
 *
 * Picker = Siap Dikirim + Sedang Dipakai (Tersedia dilarang). Per baris:
 * jenis visa, tanggal mulai, durasi bulan, optional end date. Max 50.
 * Submit membuat pending PLACEMENT_BATCH; source tidak berubah.
 */
final class PlacementBatchPanel extends Component
{
    public int $containerId;

    public int $version;

    public string $search = '';

    public string $defaultVisaId = '';

    public string $defaultStartDate = '';

    public string $defaultDuration = '12';

    /**
     * @var array<int, array{participation_id: int, visa_id: int, tanggal_mulai_kerja: string, durasi_kontrak_bulan: int, tanggal_berakhir_kontrak: string|null}>
     */
    public array $rows = [];

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

        return view('livewire.placement.placement-batch-panel', [
            'sources' => app(PlacementQueryService::class)->eligibleSourcesForBatch(Auth::user(), $this->search),
            'visaOptions' => app(LookupService::class)->optionsById('jenis_visa', app()->getLocale()),
        ]);
    }

    public function toggle(int $candidateId, int $participationId, ?int $defaultVisaId): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if (isset($this->rows[$candidateId])) {
            unset($this->rows[$candidateId]);

            return;
        }

        if (count($this->rows) >= 50) {
            $this->actionError = __('ui.placement.batch.max_reached');

            return;
        }

        $this->rows[$candidateId] = [
            'participation_id' => $participationId,
            'visa_id' => $this->defaultVisaId !== '' ? (int) $this->defaultVisaId : (int) ($defaultVisaId ?? 0),
            'tanggal_mulai_kerja' => $this->defaultStartDate,
            'durasi_kontrak_bulan' => max(1, (int) $this->defaultDuration),
            'tanggal_berakhir_kontrak' => null,
        ];
    }

    public function applyDefaults(): void
    {
        foreach (array_keys($this->rows) as $candidateId) {
            $this->rows[$candidateId]['visa_id'] = $this->defaultVisaId !== '' ? (int) $this->defaultVisaId : $this->rows[$candidateId]['visa_id'];
            $this->rows[$candidateId]['tanggal_mulai_kerja'] = $this->defaultStartDate;
            $this->rows[$candidateId]['durasi_kontrak_bulan'] = max(1, (int) $this->defaultDuration);
            $this->rows[$candidateId]['tanggal_berakhir_kontrak'] = null;
        }
    }

    public function submitBatch(): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if ($this->rows === []) {
            $this->actionError = __('ui.placement.batch.none_selected');

            return;
        }

        if (count($this->rows) > 50) {
            $this->actionError = __('ui.placement.batch.max_reached');

            return;
        }

        foreach ($this->rows as $row) {
            if (trim((string) ($row['tanggal_mulai_kerja'] ?? '')) === ''
                || (int) ($row['durasi_kontrak_bulan'] ?? 0) < 1
                || (int) ($row['visa_id'] ?? 0) < 1
            ) {
                $this->actionError = __('ui.placement.batch.incomplete_row');

                return;
            }
        }

        try {
            $rows = array_values(array_map(
                static fn (int $candidateId, array $row): array => [
                    'candidate_id' => $candidateId,
                    'source_participation_id' => (int) $row['participation_id'],
                    'jenis_visa_id' => (int) $row['visa_id'],
                    'tanggal_mulai_kerja' => $row['tanggal_mulai_kerja'],
                    'durasi_kontrak_bulan' => (int) $row['durasi_kontrak_bulan'],
                    'tanggal_berakhir_kontrak' => trim((string) ($row['tanggal_berakhir_kontrak'] ?? '')) !== ''
                        ? $row['tanggal_berakhir_kontrak']
                        : null,
                ],
                array_keys($this->rows),
                array_values($this->rows),
            ));

            app(PlacementBatchService::class)
                ->submitBatch(Auth::user(), $this->containerId, $rows, ['version' => $this->version]);

            $this->rows = [];
            session()->flash('status', __('ui.placement.batch.submitted'));
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
