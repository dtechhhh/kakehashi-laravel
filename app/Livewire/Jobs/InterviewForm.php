<?php

namespace App\Livewire\Jobs;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Jobs\Public\InterviewQueryService;
use Modules\Jobs\Services\InterviewContainerService;
use Modules\LookupData\Public\LookupService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * UI-W4-T2 — W3 interview-container draft form (Maker).
 *
 * Save Draft produces no code/pending. Submit goes through the existing
 * InterviewContainerService (W-YYYY-NNNNN code + IC_CREATE pending in one
 * transaction). Cancel is allowed for Draft and Menunggu Approval only.
 * Optimistic `version` is sent with every write; a stale version surfaces as
 * a 409 conflict banner with reload.
 */
final class InterviewForm extends Component
{
    public ?int $containerId = null;

    public int $version = 0;

    public string $status = '';

    public bool $isEditing = false;

    public bool $readonly = false;

    public bool $canCancel = false;

    public bool $conflict = false;

    public string $judul = '';

    public string $perusahaanId = '';

    public string $posisiPekerjaanId = '';

    public string $jenisWawancara = '';

    public string $jenisVisaId = '';

    public string $tanggalWawancara = '';

    public string $targetPesertaDiterima = '';

    public string $deskripsi = '';

    public string $syarat = '';

    public array $serverErrors = [];

    public ?string $actionError = null;

    public function mount(?int $containerId = null): void
    {
        if ($containerId === null) {
            return;
        }

        $this->containerId = $containerId;
        $this->isEditing = true;

        $container = app(InterviewContainerService::class)->findOrFail($containerId);
        $this->status = (string) $container->status;
        $this->version = (int) $container->version;
        $this->judul = (string) $container->judul;
        $this->perusahaanId = (string) $container->perusahaan_id;
        $this->posisiPekerjaanId = (string) $container->posisi_pekerjaan_id;
        $this->jenisWawancara = (string) $container->jenis_wawancara;
        $this->jenisVisaId = (string) $container->jenis_visa_id;
        $this->tanggalWawancara = $container->tanggal_wawancara
            ? Carbon::parse($container->tanggal_wawancara)->format('Y-m-d')
            : '';
        $this->targetPesertaDiterima = $container->target_peserta_diterima !== null
            ? (string) $container->target_peserta_diterima
            : '';
        $this->deskripsi = (string) ($container->deskripsi ?? '');
        $this->syarat = (string) ($container->syarat ?? '');

        if ($container->status !== 'Draft') {
            $this->readonly = true;
        }

        $this->canCancel = in_array($container->status, ['Draft', 'Menunggu Approval'], true);
    }

    public function render()
    {
        Gate::authorize('jobs.execute');

        $lookup = app(LookupService::class);
        $lang = app()->getLocale();

        $posisiOptions = $lookup->optionsById('posisi_pekerjaan', $lang);
        if ($this->posisiPekerjaanId !== '' && ! array_key_exists((int) $this->posisiPekerjaanId, $posisiOptions)) {
            $posisiOptions[(int) $this->posisiPekerjaanId] = $lookup->labelById('posisi_pekerjaan', (int) $this->posisiPekerjaanId, $lang);
        }

        $visaOptions = $lookup->optionsById('jenis_visa', $lang);
        if ($this->jenisVisaId !== '' && ! array_key_exists((int) $this->jenisVisaId, $visaOptions)) {
            $visaOptions[(int) $this->jenisVisaId] = $lookup->labelById('jenis_visa', (int) $this->jenisVisaId, $lang);
        }

        return view('livewire.jobs.interview-form', [
            'perusahaanOptions' => app(InterviewQueryService::class)->perusahaanOptions(
                Auth::user(),
                $this->perusahaanId !== '' ? (int) $this->perusahaanId : null,
            ),
            'posisiOptions' => $posisiOptions,
            'visaOptions' => $visaOptions,
        ]);
    }

    public function saveDraft(): void
    {
        $this->clearActionState();

        try {
            $service = app(InterviewContainerService::class);
            $container = $this->isEditing
                ? $service->updateDraft(Auth::user(), (int) $this->containerId, $this->payload() + ['version' => $this->version])
                : $service->createDraft(Auth::user(), $this->payload());

            $this->containerId = (int) $container->id;
            $this->isEditing = true;
            $this->version = (int) $container->version;
            $this->status = (string) $container->status;
            $this->readonly = $container->status !== 'Draft';
            $this->canCancel = $container->status === 'Draft';

            session()->flash('status', __('ui.jobs.form.saved'));
            $this->redirect(route('jobs.show', $this->containerId));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->mapValidation($exception);
        }
    }

    public function submit(): void
    {
        $this->clearActionState();

        if (! $this->isEditing) {
            $this->saveDraft();

            if ($this->conflict || $this->serverErrors !== [] || $this->actionError !== null) {
                return;
            }
        }

        try {
            $container = app(InterviewContainerService::class)
                ->submit(Auth::user(), (int) $this->containerId, ['version' => $this->version]);

            session()->flash('status', __('ui.jobs.form.submitted'));
            $this->redirect(route('jobs.show', (int) $container->id));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->mapValidation($exception);
        }
    }

    public function cancel(): void
    {
        $this->clearActionState();

        try {
            app(InterviewContainerService::class)
                ->cancel(Auth::user(), (int) $this->containerId, ['version' => $this->version]);

            session()->flash('status', __('ui.jobs.form.cancelled'));
            $this->redirect(route('jobs.index'));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->mapValidation($exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'judul' => $this->judul,
            'perusahaan_id' => $this->perusahaanId !== '' ? (int) $this->perusahaanId : null,
            'posisi_pekerjaan_id' => $this->posisiPekerjaanId !== '' ? (int) $this->posisiPekerjaanId : null,
            'jenis_wawancara' => $this->jenisWawancara,
            'jenis_visa_id' => $this->jenisVisaId !== '' ? (int) $this->jenisVisaId : null,
            'tanggal_wawancara' => $this->tanggalWawancara,
            'target_peserta_diterima' => $this->targetPesertaDiterima !== '' ? (int) $this->targetPesertaDiterima : null,
            'deskripsi' => $this->deskripsi !== '' ? $this->deskripsi : null,
            'syarat' => $this->syarat !== '' ? $this->syarat : null,
        ];
    }

    private function mapValidation(ValidationException $exception): void
    {
        $this->serverErrors = [];

        foreach ($exception->errors() as $field => $messages) {
            $message = (string) collect($messages)->first();
            $translationKey = 'ui.jobs.errors.'.$message;
            $translated = __($translationKey);
            $this->serverErrors[$field] = $translated === $translationKey ? $message : $translated;
        }

        $nonFieldError = collect(['version', 'judul', 'perusahaan_id', 'posisi_pekerjaan_id', 'jenis_wawancara', 'jenis_visa_id', 'tanggal_wawancara'])
            ->first(fn (string $field): bool => isset($this->serverErrors[$field]));
        $this->actionError = $nonFieldError !== null
            ? $this->serverErrors[$nonFieldError]
            : __('ui.jobs.errors.VALIDATION_FAILED');
    }

    private function clearActionState(): void
    {
        $this->actionError = null;
        $this->serverErrors = [];
        $this->conflict = false;
    }
}
