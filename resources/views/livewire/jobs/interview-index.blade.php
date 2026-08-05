<div>
    <div class="flex flex-col gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900">{{ __('ui.jobs.list_title') }}</h1>
            <p class="mt-1 text-sm text-zinc-600">{{ __('ui.jobs.list_subtitle') }}</p>
        </div>

        <div class="grid grid-cols-1 gap-3 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm md:grid-cols-3">
            <x-input id="jobs-search" name="search" type="search" wire:model.live.debounce.400ms="search"
                label="{{ __('ui.jobs.search') }}" placeholder="{{ __('ui.common.search') }}" />
            <x-select id="jobs-status" name="status" wire:model.live="status"
                label="{{ __('ui.jobs.field.status') }}"
                :options="collect($statuses)->mapWithKeys(fn ($s) => [$s->value => __('ui.jobs.status.'.$s->value)])"
                placeholder="{{ __('ui.jobs.all') }}" />
            <div class="flex items-end">
                <x-button size="sm" variant="secondary" wire:click="resetFilters">{{ __('ui.common.filter') }}</x-button>
            </div>
        </div>

        <p wire:loading class="text-sm text-zinc-500">{{ __('ui.common.loading') }}</p>

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-100 text-xs uppercase text-zinc-600">
                    <tr>
                        @foreach ([['kode_kontainer', 'ui.jobs.field.kode_kontainer'], ['judul', 'ui.jobs.field.judul'], ['perusahaan', 'ui.jobs.field.perusahaan'], ['status', 'ui.jobs.field.status'], ['tanggal_wawancara', 'ui.jobs.field.tanggal_wawancara'], ['updated_at', 'ui.jobs.field.updated_at']] as [$column, $label])
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
                    @forelse ($containers as $container)
                        <tr class="hover:bg-zinc-50">
                            <td class="px-4 py-2.5 font-mono text-xs tabular-nums text-zinc-700">{{ $container->kode_kontainer ?: '-' }}</td>
                            <td class="px-4 py-2.5 font-medium text-zinc-900">{{ $container->judul }}</td>
                            <td class="px-4 py-2.5 text-zinc-600">{{ $container->perusahaan_nama_ja ?: '-' }}</td>
                            <td class="px-4 py-2.5">
                                <x-badge :type="match ($container->status) {
                                    'Draft' => 'neutral',
                                    'Menunggu Approval' => 'warning',
                                    'Aktif' => 'success',
                                    'Ditutup' => 'neutral',
                                    'Dibatalkan' => 'danger',
                                    default => 'neutral',
                                }"
                                    :icon="match ($container->status) {
                                        'Draft', 'Ditutup' => 'dot',
                                        'Menunggu Approval' => 'clock',
                                        'Aktif' => 'check-circle',
                                        'Dibatalkan' => 'x-circle',
                                        default => 'dot',
                                    }">
                                    {{ __('ui.jobs.status.'.$container->status) }}
                                </x-badge>
                            </td>
                            <td class="px-4 py-2.5 tabular-nums text-zinc-600">
                                {{ $container->tanggal_wawancara ? \Illuminate\Support\Carbon::parse($container->tanggal_wawancara)->format(__('ui.date_time_format')) : '-' }}
                            </td>
                            <td class="px-4 py-2.5 tabular-nums text-zinc-600">
                                {{ \Illuminate\Support\Carbon::parse($container->updated_at)->format(__('ui.date_time_format')) }}
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <x-button size="sm" variant="ghost" :href="route('jobs.show', $container->id)">{{ __('ui.common.view') }}</x-button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10">
                                <x-state type="empty" title="{{ __('ui.jobs.empty.title') }}"
                                    description="{{ __('ui.jobs.empty.description') }}" class="!border-0 !shadow-none" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="border-t border-zinc-200 px-4 py-3">
                {{ $containers->links() }}
            </div>
        </div>
    </div>
</div>
