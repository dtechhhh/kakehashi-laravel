<div>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-sm text-zinc-500">
                    <a href="{{ route('placements.index') }}" class="text-blue-600 hover:underline">{{ __('ui.placement.list_title') }}</a>
                    <span class="mx-1" aria-hidden="true">/</span>
                    {{ $isEditing ? __('ui.placement.form.edit_title') : __('ui.placement.form.create_title') }}
                </p>
                <h1 class="mt-1 text-2xl font-semibold text-zinc-900">{{ $isEditing ? __('ui.placement.form.edit_title') : __('ui.placement.form.create_title') }}</h1>
                @if ($isEditing)
                    <p class="mt-1 font-mono text-xs tabular-nums text-zinc-600">{{ __('ui.placement.form.version', ['version' => $version]) }}</p>
                @endif
            </div>
            @if ($readonly)
                <x-badge type="info" icon="lock">{{ __('ui.placement.form.readonly') }}</x-badge>
            @elseif ($isEditing)
                <x-badge type="neutral" icon="dot">{{ __('ui.placement.status.Draft') }}</x-badge>
            @endif
        </div>

        @if ($conflict)
            <x-state type="conflict" />
        @endif

        @if ($actionError)
            <x-alert type="error" wire:key="error">{{ $actionError }}</x-alert>
        @endif

        @if ($readonly && $status === 'Menunggu Approval')
            <x-alert type="info">{{ __('ui.placement.form.pending_note') }}</x-alert>
        @elseif ($readonly)
            <x-alert type="info">{{ __('ui.placement.form.readonly_note') }}</x-alert>
        @endif

        <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm" @if ($readonly) inert @endif>
            <h2 class="text-lg font-semibold text-zinc-900">{{ __('ui.placement.form.detail_section') }}</h2>
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-input wire:model="nama" label="{{ __('ui.placement.field.nama') }}" required
                    :error="$serverErrors['nama'] ?? null" />
                <x-select wire:model="perusahaanId" label="{{ __('ui.placement.field.perusahaan') }}" required
                    :options="$perusahaanOptions" :error="$serverErrors['perusahaan_id'] ?? null"
                    placeholder="{{ __('ui.placement.form.select_placeholder') }}" />
            </div>
            @if ($isEditing)
                <p class="mt-3 text-xs text-zinc-500">{{ __('ui.placement.form.company_immutable_hint') }}</p>
            @endif
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-zinc-500">
                @if ($isEditing)
                    {{ __('ui.placement.form.status_hint', ['status' => __('ui.placement.status.'.$status)]) }}
                @else
                    {{ __('ui.placement.form.create_hint') }}
                @endif
            </p>
            <div class="flex flex-wrap gap-2">
                @if ($canCancel)
                    <x-button variant="secondary" wire:click="cancel">{{ __('ui.placement.form.cancel') }}</x-button>
                @endif
                @if (! $readonly)
                    <x-button variant="secondary" wire:click="saveDraft">{{ __('ui.placement.form.save_draft') }}</x-button>
                    <x-button wire:click="submit">{{ __('ui.placement.form.submit') }}</x-button>
                @endif
            </div>
        </div>
    </div>
</div>
