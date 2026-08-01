<div>
    <div class="flex flex-col gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900">{{ __('ui.queue.title') }}</h1>
            <p class="mt-1 text-sm text-zinc-600">{{ __('ui.queue.subtitle') }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="flex rounded-lg border border-zinc-200 bg-white p-0.5" role="tablist" aria-label="{{ __('ui.queue.tabs_label') }}">
                <button type="button" wire:click="setTab('lookup_request')" role="tab"
                    :aria-selected="$tab === 'lookup_request' ? 'true' : 'false'"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 {{ $tab === 'lookup_request' ? 'bg-zinc-900 text-white' : 'text-zinc-600 hover:text-zinc-900' }}">
                    {{ __('ui.queue.tab_lookup') }}
                </button>
                <button type="button" wire:click="setTab('company_request')" role="tab"
                    :aria-selected="$tab === 'company_request' ? 'true' : 'false'"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 {{ $tab === 'company_request' ? 'bg-zinc-900 text-white' : 'text-zinc-600 hover:text-zinc-900' }}">
                    {{ __('ui.queue.tab_company') }}
                </button>
            </div>
            <div class="flex gap-1">
                @foreach (['pending' => 'ui.queue.status_pending', 'approved' => 'ui.queue.status_approved', 'rejected' => 'ui.queue.status_rejected'] as $value => $label)
                    <button type="button" wire:click="setStatus('{{ $value }}')"
                        class="rounded-full px-2.5 py-1 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 {{ $status === $value ? 'bg-zinc-200 text-zinc-900' : 'text-zinc-600 hover:bg-zinc-100' }}">
                        {{ __($label) }}
                    </button>
                @endforeach
            </div>
        </div>

        @if ($conflict)
            <x-state type="conflict" />
        @endif

        @if ($actionError)
            <x-alert type="error" wire:key="error">{{ $actionError }}</x-alert>
        @endif
        @if ($actionSuccess)
            <x-alert type="success" wire:key="success">{{ $actionSuccess }}</x-alert>
        @endif

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-100 text-xs uppercase text-zinc-600">
                    <tr>
                        @if ($tab === 'lookup_request')
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.queue.table_name') }}</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.lookup.code') }}</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.lookup.label_id') }}</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.lookup.label_ja') }}</th>
                        @else
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.company.nama_ja') }}</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.company.nama_id') }}</th>
                        @endif
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.queue.requested_by') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.queue.reason') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.lookup.status') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-semibold">{{ __('ui.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($requests as $request)
                        <tr class="align-top hover:bg-zinc-50">
                            @if ($tab === 'lookup_request')
                                <td class="px-4 py-2.5 font-mono text-xs text-zinc-600">{{ $request->lookup_table }}</td>
                                <td class="px-4 py-2.5 font-mono text-xs text-zinc-700">{{ $request->code }}</td>
                                <td class="px-4 py-2.5 text-zinc-900">{{ $request->label_id }}</td>
                                <td class="px-4 py-2.5 text-zinc-900">{{ $request->label_ja }}</td>
                            @else
                                <td class="px-4 py-2.5 font-medium text-zinc-900">{{ $request->nama_ja }}</td>
                                <td class="px-4 py-2.5 text-zinc-600">{{ $request->nama_id ?: '-' }}</td>
                            @endif
                            <td class="px-4 py-2.5 text-zinc-700">{{ $request->requested_by_name ?? '#' . $request->requested_by }}</td>
                            <td class="max-w-56 px-4 py-2.5 text-zinc-600">
                                @if ($request->reason)
                                    <span class="line-clamp-2">{{ $request->reason }}</span>
                                @else
                                    <span class="text-zinc-400">-</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                <x-badge :type="$request->status === 'pending' ? 'warning' : ($request->status === 'approved' ? 'success' : 'danger')"
                                    :icon="$request->status === 'pending' ? 'clock' : ($request->status === 'approved' ? 'check-circle' : 'x-circle')">
                                    {{ __($request->status === 'pending' ? 'ui.queue.status_pending' : ($request->status === 'approved' ? 'ui.queue.status_approved' : 'ui.queue.status_rejected')) }}
                                </x-badge>
                            </td>
                            <td class="px-4 py-2.5">
                                @if ($request->status === 'pending')
                                    @if ((int) $request->requested_by === $requesterId)
                                        <span class="text-xs text-zinc-400">{{ __('ui.queue.self_guard') }}</span>
                                    @else
                                        <div class="flex flex-col items-end gap-1.5">
                                            @if ($tab === 'lookup_request')
                                                @php $extra = json_decode((string) $request->extra, true); @endphp
                                                @if ($extra)
                                                    <p class="text-xs text-zinc-500">{{ __('ui.queue.extra_hint') }}</p>
                                                @endif
                                            @endif
                                            <x-textarea :id="'reject-note-'.$request->id" name="reject_note" rows="2"
                                                wire:model="rejectNotes.{{ $request->id }}" class="w-64"
                                                :label="__('ui.queue.reject_note')" placeholder="{{ __('ui.queue.reject_note_placeholder') }}" />
                                            <div class="flex gap-1.5">
                                                <x-button size="sm" variant="secondary" wire:click="approve({{ $request->id }})">{{ __('ui.queue.approve') }}</x-button>
                                                <x-button size="sm" variant="destructive" wire:click="reject({{ $request->id }})">{{ __('ui.queue.reject') }}</x-button>
                                            </div>
                                        </div>
                                    @endif
                                @else
                                    <div class="text-right text-xs text-zinc-500">
                                        <p>{{ __('ui.queue.reviewed_by') }}: {{ $request->reviewed_by_name ?? '#' . $request->reviewed_by }}</p>
                                        @if ($request->note_checker)
                                            <p class="mt-0.5 max-w-56">{{ $request->note_checker }}</p>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-10">
                                <x-state type="empty" class="!border-0 !shadow-none" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="border-t border-zinc-200 px-4 py-3">
                {{ $requests->links() }}
            </div>
        </div>
    </div>

    <livewire:step-up-modal />
</div>
