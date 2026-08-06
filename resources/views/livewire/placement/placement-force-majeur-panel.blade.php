<div class="rounded-lg border border-amber-200 bg-amber-50/40 p-4 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-sm font-semibold text-zinc-900">{{ __('ui.placement.force_majeur.title') }}</h2>
            <p class="mt-1 text-xs text-zinc-500">{{ __('ui.placement.force_majeur.subtitle') }}</p>
        </div>
        <x-badge type="warning" icon="clock">{{ __('ui.placement.force_majeur.exceptional_label') }}</x-badge>
    </div>

    @if ($conflict)
        <x-state type="conflict" class="mt-3" />
    @endif

    @if ($actionError)
        <x-alert type="error" wire:key="fm-error" class="mt-3">{{ $actionError }}</x-alert>
    @endif

    <div class="mt-4">
        <h3 class="text-xs font-semibold uppercase text-zinc-500">{{ __('ui.placement.force_majeur.candidate') }}</h3>
        <x-input type="search" wire:model.live.debounce.400ms="search" class="mt-2 max-w-md"
            label="{{ __('ui.placement.force_majeur.search') }}" placeholder="{{ __('ui.common.search') }}" />
        <div class="mt-2 overflow-hidden rounded-lg border border-zinc-200 bg-white">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-100 text-xs uppercase text-zinc-600">
                    <tr>
                        <th scope="col" class="px-3 py-2 font-semibold">{{ __('ui.placement.force_majeur.candidate') }}</th>
                        <th scope="col" class="px-3 py-2 text-right font-semibold">{{ __('ui.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($candidates as $candidate)
                        <tr class="hover:bg-zinc-50">
                            <td class="px-3 py-2">
                                <span class="font-mono text-xs tabular-nums text-zinc-700">{{ $candidate->candidate_nomor_induk }}</span>
                                <span class="ml-2 font-medium text-zinc-900">{{ $candidate->candidate_nama_alphabet }}</span>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <x-button size="sm" :variant="$candidateId === $candidate->candidate_id ? 'secondary' : 'ghost'"
                                    wire:click="selectCandidate({{ $candidate->candidate_id }})">
                                    {{ $candidateId === $candidate->candidate_id ? __('ui.placement.force_majeur.selected_mark') : __('ui.placement.force_majeur.select') }}
                                </x-button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2" class="px-3 py-8 text-center text-sm text-zinc-500">
                                {{ __('ui.placement.force_majeur.no_candidates') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-select wire:model="kategoriId" label="{{ __('ui.placement.force_majeur.kategori') }}" required
            :options="$kategoriOptions" placeholder="{{ __('ui.placement.form.select_placeholder') }}" />
        <x-select wire:model="visaId" label="{{ __('ui.placement.field.visa') }}" required
            :options="$visaOptions" placeholder="{{ __('ui.placement.form.select_placeholder') }}" />
        <x-textarea wire:model="alasan" label="{{ __('ui.placement.force_majeur.alasan') }}" required
            class="md:col-span-2" />
        <x-input type="date" wire:model="tanggalMulai" label="{{ __('ui.placement.force_majeur.start') }}" required />
        <x-input type="number" min="1" wire:model="durasi" label="{{ __('ui.placement.force_majeur.duration') }}" />
        <x-input type="date" wire:model="tanggalBerakhir" label="{{ __('ui.placement.force_majeur.end_override') }}" />
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
        <p class="mr-auto text-xs text-zinc-500">{{ __('ui.placement.force_majeur.no_stepup_note') }}</p>
        <x-button wire:click="submit">{{ __('ui.placement.force_majeur.submit') }}</x-button>
    </div>
</div>
