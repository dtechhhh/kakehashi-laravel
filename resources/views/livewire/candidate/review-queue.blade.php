<div>
    <div class="flex flex-col gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900">{{ __('ui.review.title') }}</h1>
            <p class="mt-1 text-sm text-zinc-600">{{ __('ui.review.subtitle') }}</p>
        </div>

        <div class="flex gap-1">
            @foreach (['pending' => 'ui.review.status_pending', 'approved' => 'ui.review.status_approved', 'rejected' => 'ui.review.status_rejected'] as $value => $label)
                <button type="button" wire:click="setStatus('{{ $value }}')"
                    class="rounded-full px-2.5 py-1 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 {{ $status === $value ? 'bg-zinc-200 text-zinc-900' : 'text-zinc-600 hover:bg-zinc-100' }}">
                    {{ __($label) }}
                </button>
            @endforeach
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
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.review.type') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.candidate.nama') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.candidate.nik') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.queue.requested_by') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.review.requested_at') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-semibold">{{ __('ui.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($rows as $row)
                        <tr class="align-top hover:bg-zinc-50">
                            <td class="px-4 py-2.5">
                                <x-badge :type="$row->pending_type === 'CANDIDATE_REVISION' ? 'info' : 'warning'"
                                    :icon="$row->pending_type === 'CANDIDATE_REVISION' ? 'badge-check' : 'clock'">
                                    {{ $row->pending_type === 'CANDIDATE_REVISION' ? __('ui.review.type_revision') : __('ui.review.type_new') }}
                                </x-badge>
                            </td>
                            <td class="px-4 py-2.5">
                                <a href="{{ route('candidate.show', $row->candidate_id) }}"
                                    class="font-medium text-blue-600 hover:underline focus-visible:outline-2 focus-visible:outline-blue-600">{{ $row->nama_alphabet }}</a>
                                @if ($row->pending_type === 'CANDIDATE_REVISION')
                                    <span class="ml-1 text-xs text-zinc-400">{{ __('ui.review.revision_note') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs tabular-nums text-zinc-700">{{ $row->nomor_induk_display ?: '-' }}</td>
                            <td class="px-4 py-2.5 text-zinc-700">{{ $row->requested_by_name ?? '#' . $row->requested_by }}</td>
                            <td class="px-4 py-2.5 tabular-nums text-zinc-600">{{ \Illuminate\Support\Carbon::parse($row->requested_at)->format(__('ui.date_time_format')) }}</td>
                            <td class="px-4 py-2.5">
                                @if ($status === 'pending')
                                    @if ((int) $row->requested_by === $requesterId)
                                        <span class="text-xs text-zinc-400">{{ __('ui.queue.self_guard') }}</span>
                                    @else
                                        <div class="flex flex-col items-end gap-1.5">
                                            @if ($row->pending_type === 'CANDIDATE_REVISION')
                                                <a href="{{ route('candidate.revision', $row->candidate_id) }}"
                                                    class="text-xs text-blue-600 hover:underline">{{ __('ui.review.view_diff') }}</a>
                                            @endif
                                            <x-textarea :id="'reject-note-'.$row->pending_id" name="reject_note" rows="2"
                                                wire:model="rejectNotes.{{ $row->pending_id }}" class="w-64"
                                                :label="__('ui.queue.reject_note')" placeholder="{{ __('ui.queue.reject_note_placeholder') }}" />
                                            <div class="flex gap-1.5">
                                                <x-button size="sm" variant="secondary" wire:click="approve({{ $row->pending_id }}, {{ $row->candidate_version }})">{{ __('ui.queue.approve') }}</x-button>
                                                <x-button size="sm" variant="destructive" wire:click="reject({{ $row->pending_id }}, {{ $row->candidate_version }})">{{ __('ui.queue.reject') }}</x-button>
                                            </div>
                                        </div>
                                    @endif
                                @else
                                    <span class="text-xs text-zinc-500">{{ __('ui.review.decided') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10">
                                <x-state type="empty" class="!border-0 !shadow-none" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="border-t border-zinc-200 px-4 py-3">
                {{ $rows->links() }}
            </div>
        </div>
    </div>
</div>
