@props(['field', 'table', 'label', 'required' => false])

<div>
    <x-select wire:model="{{ $field }}" :label="$label" :required="$required" :options="$options($table)" />

    <div class="mt-1">
        @if ($lookupTable === $table)
            <div class="rounded-md border border-zinc-200 bg-zinc-50 p-2">
                <x-input wire:model="lookupLabelId" label="{{ __('ui.form.lookup_label_id') }}" required />
                <x-input wire:model="lookupLabelJa" label="{{ __('ui.form.lookup_label_ja') }}" required class="mt-2" />
                <x-input wire:model="lookupReason" label="{{ __('ui.form.lookup_reason') }}" class="mt-2" />
                <div class="mt-2 flex gap-2">
                    <x-button size="sm" wire:click="submitLookupRequest">{{ __('ui.form.lookup_submit') }}</x-button>
                    <x-button size="sm" variant="secondary" wire:click="closeLookupRequest">{{ __('ui.common.cancel') }}</x-button>
                </div>
                @if ($lookupRequested)
                    <p class="mt-2 text-xs text-warning-text">{{ __('ui.form.lookup_pending') }}</p>
                @elseif ($lookupStatus)
                    <p class="mt-2 text-xs text-red-600">{{ $lookupStatus }}</p>
                @endif
            </div>
        @else
            <button type="button" wire:click="openLookupRequest('{{ $table }}')"
                class="text-xs text-blue-600 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                {{ __('ui.form.lookup_request_link') }}
            </button>
        @endif
    </div>
</div>
