<div>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-sm text-zinc-500">
                    <a href="{{ route('jobs.index') }}" class="text-blue-600 hover:underline">{{ __('ui.jobs.list_title') }}</a>
                    <span class="mx-1" aria-hidden="true">/</span>
                    {{ $isEditing ? __('ui.jobs.form.edit_title') : __('ui.jobs.form.create_title') }}
                </p>
                <h1 class="mt-1 text-2xl font-semibold text-zinc-900">{{ $isEditing ? __('ui.jobs.form.edit_title') : __('ui.jobs.form.create_title') }}</h1>
                @if ($isEditing)
                    <p class="mt-1 font-mono text-xs tabular-nums text-zinc-600">{{ __('ui.jobs.form.version', ['version' => $version]) }}</p>
                @endif
            </div>
            @if ($readonly)
                <x-badge type="info" icon="lock">{{ __('ui.jobs.form.readonly') }}</x-badge>
            @elseif ($isEditing)
                <x-badge type="neutral" icon="dot">{{ __('ui.jobs.status.Draft') }}</x-badge>
            @endif
        </div>

        @if ($conflict)
            <x-state type="conflict" />
        @endif

        @if ($actionError)
            <x-alert type="error" wire:key="error">{{ $actionError }}</x-alert>
        @endif

        @if ($readonly && $status === 'Menunggu Approval')
            <x-alert type="info">{{ __('ui.jobs.form.pending_note') }}</x-alert>
        @elseif ($readonly)
            <x-alert type="info">{{ __('ui.jobs.form.readonly_note') }}</x-alert>
        @endif

        <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm" @if ($readonly) inert @endif>
            <h2 class="text-lg font-semibold text-zinc-900">{{ __('ui.jobs.form.detail_section') }}</h2>
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-input wire:model="judul" label="{{ __('ui.jobs.field.judul') }}" required
                    :error="$serverErrors['judul'] ?? null" />
                <x-select wire:model="perusahaanId" label="{{ __('ui.jobs.field.perusahaan') }}" required
                    :options="$perusahaanOptions" :error="$serverErrors['perusahaan_id'] ?? null"
                    placeholder="{{ __('ui.jobs.form.select_placeholder') }}" />
                <x-select wire:model="posisiPekerjaanId" label="{{ __('ui.jobs.field.posisi_pekerjaan') }}" required
                    :options="$posisiOptions" :error="$serverErrors['posisi_pekerjaan_id'] ?? null"
                    placeholder="{{ __('ui.jobs.form.select_placeholder') }}" />
                <x-select wire:model="jenisWawancara" label="{{ __('ui.jobs.field.jenis_wawancara') }}" required
                    :options="collect(['OFFLINE', 'ONLINE'])->mapWithKeys(fn ($value) => [$value => __('ui.jobs.jenis_wawancara.'.$value)])->all()"
                    :error="$serverErrors['jenis_wawancara'] ?? null"
                    placeholder="{{ __('ui.jobs.form.select_placeholder') }}" />
                <x-select wire:model="jenisVisaId" label="{{ __('ui.jobs.field.jenis_visa') }}" required
                    :options="$visaOptions" :error="$serverErrors['jenis_visa_id'] ?? null"
                    placeholder="{{ __('ui.jobs.form.select_placeholder') }}" />
                <x-input wire:model="tanggalWawancara" type="date" label="{{ __('ui.jobs.field.tanggal_wawancara') }}" required
                    :error="$serverErrors['tanggal_wawancara'] ?? null" />
                <x-input wire:model="targetPesertaDiterima" type="number" min="0"
                    label="{{ __('ui.jobs.field.target_peserta_diterima') }}" />
                <x-textarea wire:model="deskripsi" label="{{ __('ui.jobs.field.deskripsi') }}" class="md:col-span-2" />
                <x-textarea wire:model="syarat" label="{{ __('ui.jobs.field.syarat') }}" class="md:col-span-2" />
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-zinc-500">
                @if ($isEditing)
                    {{ __('ui.jobs.form.status_hint', ['status' => __('ui.jobs.status.'.$status)]) }}
                @else
                    {{ __('ui.jobs.form.create_hint') }}
                @endif
            </p>
            <div class="flex flex-wrap gap-2">
                @if ($canCancel)
                    <x-button variant="secondary" wire:click="cancel">{{ __('ui.jobs.form.cancel') }}</x-button>
                @endif
                @if (! $readonly)
                    <x-button variant="secondary" wire:click="saveDraft">{{ __('ui.jobs.form.save_draft') }}</x-button>
                    <x-button wire:click="submit">{{ __('ui.jobs.form.submit') }}</x-button>
                @endif
            </div>
        </div>
    </div>
</div>
