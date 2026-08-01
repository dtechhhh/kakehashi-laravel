<div>
    @if ($notFound)
        <x-state type="not-found">
            <x-button variant="secondary" href="{{ route('candidate.index') }}">{{ __('ui.common.back') }}</x-button>
        </x-state>
    @else
        <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-zinc-500">
                        <a href="{{ route('candidate.show', $revision->parent_candidate_id) }}" class="text-blue-600 hover:underline">{{ __('ui.candidate.list_title') }}</a>
                        <span class="mx-1" aria-hidden="true">/</span>
                        {{ __('ui.review.revision_diff_title') }}
                    </p>
                    <h1 class="mt-1 text-2xl font-semibold text-zinc-900">{{ $revision->nama_alphabet }}</h1>
                    <p class="mt-1 font-mono text-sm tabular-nums text-zinc-600">{{ $main->nomor_induk ?: '-' }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-badge type="info" icon="badge-check">{{ __('ui.review.type_revision') }}</x-badge>
                    <x-badge :type="$revision->status_approval === 'Menunggu Tinjauan-REVISI' ? 'warning' : ($revision->status_approval === 'Ditolak' ? 'danger' : 'neutral')"
                        :icon="$revision->status_approval === 'Menunggu Tinjauan-REVISI' ? 'clock' : 'dot'">
                        {{ __('ui.candidate.status.'.$revision->status_approval) }}
                    </x-badge>
                </div>
            </div>

            @if ($activePending)
                <p class="rounded-md bg-warning-bg px-3 py-2 text-sm text-warning-text">{{ __('ui.candidate.pending_overlay') }}</p>
            @endif

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
                    <h2 class="text-base font-semibold text-zinc-900">{{ __('ui.review.main_version') }}</h2>
                    <p class="mt-1 text-sm text-zinc-500">{{ __('ui.review.main_version_note') }}</p>
                </div>
                <div class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
                    <h2 class="text-base font-semibold text-zinc-900">{{ __('ui.review.revision_version') }}</h2>
                    <p class="mt-1 text-sm text-zinc-500">{{ __('ui.review.revision_version_note') }}</p>
                </div>
            </div>

            @if ($diffRows === [] && $childSummaries === [])
                <x-state type="empty" :title="__('ui.review.no_changes_title')" :description="__('ui.review.no_changes_description')" />
            @else
                <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-zinc-100 text-xs uppercase text-zinc-600">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.review.field') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.review.main_value') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.review.revision_value') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100">
                            @forelse ($diffRows as $row)
                                <tr class="bg-warning-bg/40">
                                    <td class="px-4 py-2.5 font-medium text-zinc-900">{{ $row['label'] }}</td>
                                    <td class="px-4 py-2.5 text-zinc-600 line-through">{{ $row['main'] }}</td>
                                    <td class="px-4 py-2.5 font-medium text-zinc-900">{{ $row['revision'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-4 py-4 text-sm text-zinc-500">{{ __('ui.review.no_main_changes') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($childSummaries !== [])
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach ($childSummaries as $summary)
                            <div class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
                                <h3 class="text-sm font-semibold text-zinc-900">{{ $summary['title'] }}</h3>
                                <ul class="mt-2 flex flex-col gap-1 text-sm text-zinc-600">
                                    @if ($summary['added'] > 0)
                                        <li>{{ __('ui.review.children_added', ['count' => $summary['added']]) }}</li>
                                    @endif
                                    @if ($summary['removed'] > 0)
                                        <li>{{ __('ui.review.children_removed', ['count' => $summary['removed']]) }}</li>
                                    @endif
                                    @if ($summary['changed'] > 0)
                                        <li>{{ __('ui.review.children_changed', ['count' => $summary['changed']]) }}</li>
                                    @endif
                                </ul>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif

            <div class="flex gap-2">
                <x-button variant="secondary" :href="route('candidate.show', $revision->parent_candidate_id)">{{ __('ui.common.back') }}</x-button>
                @can('candidate.create')
                    @if (in_array($revision->status_approval, ['Draft', 'Ditolak'], true))
                        <x-button :href="route('candidate.edit', $revision->id)">{{ __('ui.review.edit_revision') }}</x-button>
                    @endif
                @endcan
            </div>
        </div>
    @endif
</div>
