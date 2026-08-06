<div>
    <div class="flex flex-col gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900">{{ __('ui.jobs.queue.title') }}</h1>
            <p class="mt-1 text-sm text-zinc-600">{{ __('ui.jobs.queue.subtitle') }}</p>
        </div>

        @if ($conflict)
            <x-state type="conflict" />
        @endif

        @if ($actionError)
            <x-alert type="error" wire:key="error">{{ $actionError }}</x-alert>
        @endif

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-100 text-xs uppercase text-zinc-600">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.jobs.field.kode_kontainer') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.jobs.field.judul') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.jobs.field.perusahaan') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.jobs.queue.requested_at') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.jobs.queue.requested_by') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-semibold">{{ __('ui.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($queue as $row)
                        <tr class="hover:bg-zinc-50">
                            <td class="px-4 py-2.5 font-mono text-xs tabular-nums text-zinc-700">{{ $row->kode_kontainer ?: '-' }}</td>
                            <td class="px-4 py-2.5">
                                <a href="{{ route('jobs.show', $row->container_id) }}" class="font-medium text-blue-600 hover:underline">{{ $row->judul }}</a>
                            </td>
                            <td class="px-4 py-2.5 text-zinc-600">{{ $row->perusahaan_nama_ja ?: '-' }}</td>
                            <td class="px-4 py-2.5 tabular-nums text-zinc-600">{{ \Illuminate\Support\Carbon::parse($row->requested_at)->format(__('ui.date_time_format')) }}</td>
                            <td class="px-4 py-2.5 text-zinc-600">{{ $row->requested_by_name ?: '-' }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    <x-button size="sm" wire:click="approve({{ $row->pending_id }}, {{ $row->container_version }})">
                                        {{ __('ui.jobs.queue.approve') }}
                                    </x-button>
                                    @if ($rejectingId === $row->pending_id)
                                        <x-input wire:model="rejectNote" class="w-56"
                                            label="{{ __('ui.jobs.queue.note_label') }}"
                                            placeholder="{{ __('ui.jobs.queue.note_placeholder') }}" />
                                        <x-button size="sm" variant="destructive" wire:click="reject({{ $row->pending_id }}, {{ $row->container_version }})">
                                            {{ __('ui.jobs.queue.reject') }}
                                        </x-button>
                                        <x-button size="sm" variant="ghost" wire:click="cancelReject">{{ __('ui.jobs.queue.cancel') }}</x-button>
                                    @else
                                        <x-button size="sm" variant="secondary" wire:click="startReject({{ $row->pending_id }})">
                                            {{ __('ui.jobs.queue.reject') }}
                                        </x-button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10">
                                <x-state type="empty" title="{{ __('ui.jobs.queue.empty') }}"
                                    description="{{ __('ui.jobs.queue.empty_description') }}" class="!border-0 !shadow-none" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="border-t border-zinc-200 px-4 py-3">
                {{ $queue->links() }}
            </div>
        </div>
    </div>
</div>
