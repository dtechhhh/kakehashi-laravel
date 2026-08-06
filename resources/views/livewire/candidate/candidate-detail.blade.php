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
                        <a href="{{ route('candidate.index') }}" class="text-blue-600 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">{{ __('ui.candidate.list_title') }}</a>
                        <span class="mx-1" aria-hidden="true">/</span>
                    </p>
                    <h1 class="mt-1 text-2xl font-semibold text-zinc-900">{{ $candidate->nama_alphabet }}</h1>
                    <p class="mt-1 font-mono text-sm tabular-nums text-zinc-600">{{ $nomorIndukDisplay ?: __('ui.candidate.no_nik') }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
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
                    <x-badge :type="$candidate->status_ketersediaan === 'TERSEDIA' ? 'success' : 'neutral'"
                        :icon="$candidate->status_ketersediaan === 'TERSEDIA' ? 'check' : 'lock'">
                        {{ __('ui.candidate.availability.'.$candidate->status_ketersediaan) }}
                    </x-badge>
                    <x-badge type="info" icon="clock">{{ __('ui.candidate.version', ['version' => $candidate->version]) }}</x-badge>
                </div>
            </div>

            @if ($activePending)
                <p class="rounded-md bg-warning-bg px-3 py-2 text-sm text-warning-text">{{ __('ui.candidate.pending_overlay') }}</p>
            @endif

            @if ($conflict)
                <x-state type="conflict" />
            @endif

            @if ($actionError)
                <x-alert type="error" wire:key="error">{{ $actionError }}</x-alert>
            @endif

            <div class="flex flex-wrap gap-2">
                @can('candidate.create')
                    @if (! $isRevision && $candidate->status_approval === 'Ditolak')
                        <x-button size="sm" :href="route('candidate.edit', $candidate->id)">{{ __('ui.form.fix_and_resubmit') }}</x-button>
                    @endif
                @endcan
                @if ($isRevision)
                    <x-button size="sm" variant="secondary" :href="route('candidate.revision', $candidate->id)">{{ __('ui.review.view_diff') }}</x-button>
                @elseif ($openRevisionId !== null)
                    <x-button size="sm" variant="secondary" :href="route('candidate.revision', $openRevisionId)">{{ __('ui.review.view_open_revision') }}</x-button>
                @else
                    @can('candidate.create')
                        @if ($candidate->status_approval === 'Disetujui')
                            <x-button size="sm" wire:click="startRevision">{{ __('ui.review.start_revision') }}</x-button>
                        @endif
                    @endcan
                @endif
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div class="lg:col-span-1">
                    <div class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
                        <h2 class="text-base font-semibold text-zinc-900">{{ __('ui.candidate.section.photo') }}</h2>
                        <div wire:init="loadPhoto" class="mt-3">
                            @if ($photoUrl)
                                <img src="{{ $photoUrl }}" alt="{{ __('ui.candidate.photo_alt', ['name' => $candidate->nama_alphabet]) }}"
                                    class="h-64 w-full rounded-md border border-zinc-200 object-cover" loading="lazy" />
                            @elseif ($photoMissing || $photo === null)
                                <div class="grid h-40 place-items-center rounded-md bg-zinc-100 text-sm text-zinc-500">
                                    {{ $photoMissing ? __('ui.candidate.photo_error') : __('ui.candidate.photo_empty') }}
                                </div>
                            @else
                                <div class="grid h-40 place-items-center" role="status">
                                    <svg class="h-6 w-6 animate-spin text-zinc-400" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"/></svg>
                                    <span class="sr-only">{{ __('ui.state.loading') }}</span>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="mt-4 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
                        <h2 class="text-base font-semibold text-zinc-900">{{ __('ui.candidate.section.document') }}</h2>
                        @forelse ($documents as $document)
                            <div class="mt-2 flex items-center justify-between gap-2 rounded-md border border-zinc-200 px-3 py-2">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-zinc-900">{{ $this->label('jenis_dokumen', $document['jenis_dokumen_id']) }}</p>
                                    @if ($document['nama_file'])
                                        <p class="truncate text-xs text-zinc-500">{{ $document['nama_file'] }}</p>
                                    @endif
                                </div>
                                <x-button size="sm" variant="secondary" wire:click="revealDocument({{ $document['id'] }})">{{ __('ui.candidate.view_document') }}</x-button>
                            </div>
                        @empty
                            <p class="mt-2 text-sm text-zinc-500">{{ __('ui.candidate.documents_empty') }}</p>
                        @endforelse
                    </div>
                </div>

                <div class="flex flex-col gap-4 lg:col-span-2">
                    @foreach ($sections as $section)
                        <div class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
                            <h2 class="text-base font-semibold text-zinc-900">{{ $section['title'] }}</h2>
                            <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
                                @foreach ($section['rows'] as $row)
                                    <div class="flex flex-col">
                                        <dt class="text-xs text-zinc-500">{{ $row['label'] }}</dt>
                                        <dd class="text-sm text-zinc-900 break-words">{{ $row['value'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
