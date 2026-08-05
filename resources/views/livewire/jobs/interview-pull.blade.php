<div class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h2 class="text-sm font-semibold text-zinc-900">{{ __('ui.jobs.pull.title') }}</h2>
            <p class="mt-0.5 text-xs text-zinc-500">{{ __('ui.jobs.pull.subtitle') }}</p>
        </div>
        <span class="text-xs tabular-nums text-zinc-500">{{ count($selected) }} / 50</span>
    </div>

    @if ($conflict)
        <x-state type="conflict" class="mt-3" />
    @endif

    @if ($actionError)
        <x-alert type="error" class="mt-3" wire:key="error">{{ $actionError }}</x-alert>
    @endif

    <div class="mt-3">
        <x-input wire:model.live.debounce.400ms="search" type="search" label="{{ __('ui.jobs.pull.search') }}"
            placeholder="{{ __('ui.common.search') }}" />
    </div>

    <div class="mt-3 max-h-96 overflow-y-auto rounded-md border border-zinc-200">
        @forelse ($candidates as $candidate)
            @php
                $pullable = $candidate->status_ketersediaan === 'TERSEDIA';
                $checked = isset($selected[$candidate->id]);
            @endphp
            <label class="flex cursor-pointer items-center gap-3 border-b border-zinc-100 px-3 py-2 text-sm last:border-0 hover:bg-zinc-50 {{ $pullable ? '' : 'cursor-not-allowed bg-zinc-50 opacity-70' }}">
                <input type="checkbox"
                    wire:change="toggle({{ $candidate->id }})"
                    @checked($checked)
                    {{ $pullable ? '' : 'disabled' }}
                    class="h-4 w-4 rounded border-zinc-300 text-zinc-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                    aria-label="{{ $candidate->nomor_induk }}" />
                <span class="font-mono text-xs tabular-nums text-zinc-700">{{ $candidate->nomor_induk }}</span>
                <span class="font-medium text-zinc-900">{{ $candidate->nama_alphabet }}</span>
                @if (! $pullable)
                    <x-badge type="neutral" icon="lock">{{ __('ui.jobs.pull.in_use') }}</x-badge>
                @else
                    <x-badge type="success" icon="check">{{ __('ui.jobs.pull.available') }}</x-badge>
                @endif
            </label>
        @empty
            <p class="px-3 py-8 text-center text-sm text-zinc-500">{{ __('ui.jobs.pull.empty') }}</p>
        @endforelse
    </div>

    <div class="mt-2">
        {{ $candidates->links() }}
    </div>

    <div class="mt-3 flex justify-end">
        <x-button wire:click="pullCandidates">{{ __('ui.jobs.pull.action') }}</x-button>
    </div>
</div>
