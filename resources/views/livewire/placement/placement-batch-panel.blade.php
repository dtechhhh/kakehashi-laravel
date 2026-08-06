<div class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-sm font-semibold text-zinc-900">{{ __('ui.placement.batch.title') }}</h2>
            <p class="mt-1 text-xs text-zinc-500">{{ __('ui.placement.batch.subtitle') }}</p>
        </div>
        <x-badge type="info" icon="clock">{{ __('ui.placement.batch.eligible_label') }}</x-badge>
    </div>

    @if ($conflict)
        <x-state type="conflict" class="mt-3" />
    @endif

    @if ($actionError)
        <x-alert type="error" wire:key="batch-error" class="mt-3">{{ $actionError }}</x-alert>
    @endif

    <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
        <x-input type="search" wire:model.live.debounce.400ms="search"
            label="{{ __('ui.placement.batch.search') }}" placeholder="{{ __('ui.common.search') }}" />
        <div class="flex flex-wrap items-end gap-2">
            <x-select wire:model="defaultVisaId" label="{{ __('ui.placement.batch.default_visa') }}"
                :options="$visaOptions" placeholder="{{ __('ui.placement.form.select_placeholder') }}" class="w-52" />
            <x-input type="date" wire:model="defaultStartDate" label="{{ __('ui.placement.batch.default_start') }}" class="w-44" />
            <x-input type="number" min="1" wire:model="defaultDuration" label="{{ __('ui.placement.batch.default_duration') }}" class="w-28" />
            <x-button size="sm" variant="secondary" wire:click="applyDefaults">{{ __('ui.placement.batch.apply_defaults') }}</x-button>
        </div>
    </div>

    @if ($rows !== [])
        <div class="mt-4">
            <h3 class="text-xs font-semibold uppercase text-zinc-500">{{ __('ui.placement.batch.selected', ['count' => count($rows)]) }}</h3>
            <div class="mt-2 overflow-x-auto rounded-lg border border-zinc-200">
                <table class="w-full text-left text-sm">
                    <thead class="bg-zinc-100 text-xs uppercase text-zinc-600">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-semibold">{{ __('ui.placement.batch.candidate') }}</th>
                            <th scope="col" class="px-3 py-2 font-semibold">{{ __('ui.placement.batch.visa') }}</th>
                            <th scope="col" class="px-3 py-2 font-semibold">{{ __('ui.placement.batch.start') }}</th>
                            <th scope="col" class="px-3 py-2 font-semibold">{{ __('ui.placement.batch.duration') }}</th>
                            <th scope="col" class="px-3 py-2 font-semibold">{{ __('ui.placement.batch.end_override') }}</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold">{{ __('ui.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @foreach ($rows as $candidateId => $row)
                            @php $source = $sources->firstWhere('candidate_id', $candidateId); @endphp
                            <tr class="hover:bg-zinc-50">
                                <td class="px-3 py-2">
                                    <span class="font-mono text-xs tabular-nums text-zinc-700">{{ $source->candidate_nomor_induk ?? '-' }}</span>
                                    <span class="ml-2 font-medium text-zinc-900">{{ $source->candidate_nama_alphabet ?? '-' }}</span>
                                </td>
                                <td class="px-3 py-2">
                                    <x-select wire:model="rows.{{ $candidateId }}.visa_id" :options="$visaOptions" class="w-44" />
                                </td>
                                <td class="px-3 py-2">
                                    <x-input type="date" wire:model="rows.{{ $candidateId }}.tanggal_mulai_kerja" class="w-40" />
                                </td>
                                <td class="px-3 py-2">
                                    <x-input type="number" min="1" wire:model="rows.{{ $candidateId }}.durasi_kontrak_bulan" class="w-24" />
                                </td>
                                <td class="px-3 py-2">
                                    <x-input type="date" wire:model="rows.{{ $candidateId }}.tanggal_berakhir_kontrak" class="w-40" />
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <x-button size="sm" variant="ghost" wire:click="toggle({{ $candidateId }}, {{ $row['participation_id'] }}, {{ $row['visa_id'] }})">
                                        {{ __('ui.placement.batch.remove') }}
                                    </x-button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="mt-4">
        <h3 class="text-xs font-semibold uppercase text-zinc-500">{{ __('ui.placement.batch.sources') }}</h3>
        <div class="mt-2 overflow-hidden rounded-lg border border-zinc-200">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-100 text-xs uppercase text-zinc-600">
                    <tr>
                        <th scope="col" class="px-3 py-2 font-semibold">{{ __('ui.placement.batch.candidate') }}</th>
                        <th scope="col" class="px-3 py-2 font-semibold">{{ __('ui.placement.batch.interview_source') }}</th>
                        <th scope="col" class="px-3 py-2 text-right font-semibold">{{ __('ui.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($sources as $source)
                        <tr class="hover:bg-zinc-50">
                            <td class="px-3 py-2">
                                <span class="font-mono text-xs tabular-nums text-zinc-700">{{ $source->candidate_nomor_induk }}</span>
                                <span class="ml-2 font-medium text-zinc-900">{{ $source->candidate_nama_alphabet }}</span>
                            </td>
                            <td class="px-3 py-2 text-zinc-600">{{ $source->interview_kode ?: '-' }}</td>
                            <td class="px-3 py-2 text-right">
                                <x-button size="sm" :variant="isset($rows[$source->candidate_id]) ? 'secondary' : 'ghost'"
                                    wire:click="toggle({{ $source->candidate_id }}, {{ $source->participation_id }}, {{ (int) $source->default_visa_id }})">
                                    {{ isset($rows[$source->candidate_id]) ? __('ui.placement.batch.selected_mark') : __('ui.placement.batch.add') }}
                                </x-button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-3 py-8 text-center text-sm text-zinc-500">
                                {{ __('ui.placement.batch.no_sources') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
        <p class="mr-auto text-xs text-zinc-500">{{ __('ui.placement.batch.max_note') }}</p>
        <x-button wire:click="submitBatch">{{ __('ui.placement.batch.submit') }}</x-button>
    </div>
</div>
