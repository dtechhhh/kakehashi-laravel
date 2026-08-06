<div>
    <div class="flex flex-col gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900">{{ __('ui.placement.list_title') }}</h1>
            <p class="mt-1 text-sm text-zinc-600">{{ __('ui.placement.list_subtitle') }}</p>
        </div>

        <div class="flex justify-end">
            @can('placement.execute')
                <x-button size="sm" :href="route('placements.create')">{{ __('ui.placement.create_title') }}</x-button>
            @endcan
        </div>

        <div class="grid grid-cols-1 gap-3 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm md:grid-cols-3">
            <x-input id="placement-search" name="search" type="search" wire:model.live.debounce.400ms="search"
                label="{{ __('ui.placement.search') }}" placeholder="{{ __('ui.common.search') }}" />
            <x-select id="placement-status" name="status" wire:model.live="status"
                label="{{ __('ui.placement.field.status') }}"
                :options="collect($statuses)->mapWithKeys(fn ($s) => [$s->value => __('ui.placement.status.'.$s->value)])"
                placeholder="{{ __('ui.placement.all') }}" />
            <div class="flex items-end">
                <x-button size="sm" variant="secondary" wire:click="resetFilters">{{ __('ui.common.filter') }}</x-button>
            </div>
        </div>

        <p wire:loading class="text-sm text-zinc-500">{{ __('ui.common.loading') }}</p>

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-100 text-xs uppercase text-zinc-600">
                    <tr>
                        @foreach ([['kode_kontainer', 'ui.placement.field.kode_kontainer'], ['nama', 'ui.placement.field.nama'], ['perusahaan', 'ui.placement.field.perusahaan'], ['status', 'ui.placement.field.status'], ['updated_at', 'ui.placement.field.updated_at']] as [$column, $label])
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
                            <td class="px-4 py-2.5 font-medium text-zinc-900">{{ $container->nama }}</td>
                            <td class="px-4 py-2.5 text-zinc-600">{{ $container->perusahaan_nama_ja ?: '-' }}</td>
                            <td class="px-4 py-2.5">
                                <x-badge :type="match ($container->status) {
                                    'Draft' => 'neutral',
                                    'Menunggu Approval' => 'warning',
                                    'Aktif' => 'success',
                                    'Arsip' => 'neutral',
                                    'Dibatalkan' => 'danger',
                                    default => 'neutral',
                                }"
                                    :icon="match ($container->status) {
                                        'Draft', 'Arsip' => 'dot',
                                        'Menunggu Approval' => 'clock',
                                        'Aktif' => 'check-circle',
                                        'Dibatalkan' => 'x-circle',
                                        default => 'dot',
                                    }">
                                    {{ __('ui.placement.status.'.$container->status) }}
                                </x-badge>
                            </td>
                            <td class="px-4 py-2.5 tabular-nums text-zinc-600">
                                {{ \Illuminate\Support\Carbon::parse($container->updated_at)->format(__('ui.date_time_format')) }}
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <x-button size="sm" variant="ghost" :href="route('placements.show', $container->id)">{{ __('ui.common.view') }}</x-button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10">
                                <x-state type="empty" title="{{ __('ui.placement.empty.title') }}"
                                    description="{{ __('ui.placement.empty.description') }}" class="!border-0 !shadow-none" />
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
