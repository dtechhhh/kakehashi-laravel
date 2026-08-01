<div>
    <div class="flex flex-col gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900">{{ __('ui.candidate.list_title') }}</h1>
            <p class="mt-1 text-sm text-zinc-600">{{ __('ui.candidate.list_subtitle') }}</p>
        </div>

        <div class="grid grid-cols-2 gap-3 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm md:grid-cols-5">
            <x-input id="candidate-search" name="search" type="search" wire:model.live.debounce.400ms="search"
                label="{{ __('ui.candidate.nama') }}" placeholder="{{ __('ui.common.search') }}" />
            <x-select id="candidate-status-approval" name="status_approval" wire:model.live="statusApproval"
                label="{{ __('ui.candidate.status_approval') }}"
                :options="collect($approvalStatuses)->mapWithKeys(fn ($s) => [$s->value => __('ui.candidate.status.'.$s->value)])"
                placeholder="{{ __('ui.candidate.all') }}" />
            <x-select id="candidate-status-avail" name="status_ketersediaan" wire:model.live="statusKetersediaan"
                label="{{ __('ui.candidate.status_ketersediaan') }}"
                :options="collect($availabilityStatuses)->mapWithKeys(fn ($s) => [$s->value => __('ui.candidate.availability.'.$s->value)])"
                placeholder="{{ __('ui.candidate.all') }}" />
            <x-input id="candidate-age-from" name="age_from" type="number" min="15" max="70" wire:model.live="ageFrom" label="{{ __('ui.candidate.age_from') }}" />
            <x-input id="candidate-age-to" name="age_to" type="number" min="15" max="70" wire:model.live="ageTo" label="{{ __('ui.candidate.age_to') }}" />
        </div>

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-100 text-xs uppercase text-zinc-600">
                    <tr>
                        @foreach ([['nomor_induk', 'ui.candidate.nik'], ['nama', 'ui.candidate.nama'], ['umur', 'ui.candidate.umur'], ['status_approval', 'ui.candidate.status_approval'], ['status_ketersediaan', 'ui.candidate.status_ketersediaan'], ['updated_at', 'ui.candidate.updated_at']] as [$column, $label])
                            <th scope="col" class="px-4 py-2.5 font-semibold">
                                <button type="button" wire:click="sortBy('{{ $column }}')"
                                    class="inline-flex items-center gap-1 hover:text-zinc-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                                    {{ __($label) }}
                                    @if ($sort === $column)
                                        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            @if ($direction === 'asc')
                                                <path d="m6 9 6 6 6-6" />
                                            @else
                                                <path d="m6 15 6-6 6 6" />
                                            @endif
                                        </svg>
                                    @endif
                                </button>
                            </th>
                        @endforeach
                        <th scope="col" class="px-4 py-2.5 text-right font-semibold">{{ __('ui.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($candidates as $candidate)
                        <tr class="hover:bg-zinc-50">
                            <td class="px-4 py-2.5 font-mono text-xs tabular-nums text-zinc-700">{{ $candidate->nomor_induk ?: '-' }}</td>
                            <td class="px-4 py-2.5 font-medium text-zinc-900">{{ $candidate->nama_alphabet }}</td>
                            <td class="px-4 py-2.5 tabular-nums text-zinc-600">{{ $this->ageOf($candidate) }} {{ __('ui.candidate.years_old') }}</td>
                            <td class="px-4 py-2.5">
                                <x-badge :type="match ($candidate->status_approval) {
                                    'Draft' => 'neutral',
                                    'Menunggu Tinjauan-BARU', 'Menunggu Tinjauan-REVISI' => 'warning',
                                    'Disetujui' => 'success',
                                    'Ditolak' => 'danger',
                                    'Diterapkan' => 'accent2',
                                    default => 'neutral',
                                }"
                                    :icon="match ($candidate->status_approval) {
                                        'Draft' => 'dot',
                                        'Menunggu Tinjauan-BARU', 'Menunggu Tinjauan-REVISI' => 'clock',
                                        'Disetujui' => 'check-circle',
                                        'Ditolak' => 'x-circle',
                                        'Diterapkan' => 'badge-check',
                                        default => 'dot',
                                    }">
                                    {{ __('ui.candidate.status.'.$candidate->status_approval) }}
                                </x-badge>
                            </td>
                            <td class="px-4 py-2.5">
                                <x-badge :type="$candidate->status_ketersediaan === 'TERSEDIA' ? 'success' : 'neutral'"
                                    :icon="$candidate->status_ketersediaan === 'TERSEDIA' ? 'check' : 'lock'">
                                    {{ __('ui.candidate.availability.'.$candidate->status_ketersediaan) }}
                                </x-badge>
                            </td>
                            <td class="px-4 py-2.5 tabular-nums text-zinc-600">{{ \Illuminate\Support\Carbon::parse($candidate->updated_at)->format(__('ui.date_time_format')) }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <x-button size="sm" variant="ghost" :href="route('candidate.show', $candidate->id)">{{ __('ui.common.view') }}</x-button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10">
                                <x-state type="empty" class="!border-0 !shadow-none" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="border-t border-zinc-200 px-4 py-3">
                {{ $candidates->links() }}
            </div>
        </div>
    </div>
</div>
