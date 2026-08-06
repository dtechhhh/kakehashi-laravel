<div>
    @if ($notFound)
        <x-state type="not-found" title="{{ __('ui.placement.not_found.title') }}"
            description="{{ __('ui.placement.not_found.description') }}" />
    @else
        <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-zinc-500">
                        <a href="{{ route('placements.index') }}" class="text-blue-600 hover:underline">{{ __('ui.placement.list_title') }}</a>
                        <span class="mx-1" aria-hidden="true">/</span>
                        {{ $container->kode_kontainer ?: '-' }}
                    </p>
                    <h1 class="mt-1 text-2xl font-semibold text-zinc-900">{{ $container->nama }}</h1>
                </div>
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
            </div>

            @if (in_array($container->status, ['Arsip', 'Dibatalkan'], true))
                <x-alert type="info">{{ __('ui.placement.readonly_note') }}</x-alert>
            @endif

            @if ($conflict)
                <x-state type="conflict" />
            @endif

            @if ($actionError)
                <x-alert type="error" wire:key="error">{{ $actionError }}</x-alert>
            @endif

            @can('placement.execute')
                @if ($isMaker && $container->status === 'Aktif' && $participantCount === 0)
                    <div class="flex flex-wrap items-center gap-2 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
                        @if ($cancelRequesting)
                            <x-input wire:model="cancelReason" class="w-72"
                                label="{{ __('ui.placement.cancel_active.reason_label') }}"
                                placeholder="{{ __('ui.placement.cancel_active.reason_placeholder') }}" />
                            <x-button size="sm" variant="destructive" wire:click="requestCancelActive">
                                {{ __('ui.placement.cancel_active.request_confirm') }}
                            </x-button>
                            <x-button size="sm" variant="ghost" wire:click="cancelCancelRequest">{{ __('ui.placement.cancel_active.cancel') }}</x-button>
                        @else
                            <x-button size="sm" variant="secondary" wire:click="startCancelRequest">
                                {{ __('ui.placement.cancel_active.request_action') }}
                            </x-button>
                        @endif
                    </div>
                @endif
            @endcan

            <div class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm md:grid-cols-3">
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('ui.placement.field.perusahaan') }}</dt>
                        <dd class="mt-0.5 font-medium text-zinc-900">{{ $container->perusahaan_nama_ja ?: '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('ui.placement.field.created_at') }}</dt>
                        <dd class="mt-0.5 tabular-nums text-zinc-900">
                            {{ \Illuminate\Support\Carbon::parse($container->created_at)->format(__('ui.date_time_format')) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('ui.placement.field.updated_at') }}</dt>
                        <dd class="mt-0.5 tabular-nums text-zinc-900">
                            {{ \Illuminate\Support\Carbon::parse($container->updated_at)->format(__('ui.date_time_format')) }}
                        </dd>
                    </div>
                    @if ($container->approved_at)
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('ui.placement.field.approved_at') }}</dt>
                            <dd class="mt-0.5 tabular-nums text-zinc-900">
                                {{ \Illuminate\Support\Carbon::parse($container->approved_at)->format(__('ui.date_time_format')) }}
                            </dd>
                        </div>
                    @endif
                    @if ($container->archived_at)
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('ui.placement.field.archived_at') }}</dt>
                            <dd class="mt-0.5 tabular-nums text-zinc-900">
                                {{ \Illuminate\Support\Carbon::parse($container->archived_at)->format(__('ui.date_time_format')) }}
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>

            @if ($pending->isNotEmpty())
                <div class="rounded-lg border border-amber-200 bg-warning-bg p-4">
                    <h2 class="text-sm font-semibold text-warning-text">{{ __('ui.placement.pending.title') }}</h2>
                    <ul class="mt-2 space-y-2 text-sm">
                        @foreach ($pending as $request)
                            <li class="flex flex-wrap items-center gap-2">
                                <x-badge type="warning" icon="clock">{{ __('ui.placement.pending.'.$request->type) }}</x-badge>
                                <span class="text-zinc-700">{{ __('ui.placement.field.created_at') }}: {{ \Illuminate\Support\Carbon::parse($request->created_at)->format(__('ui.date_time_format')) }}</span>
                            @if ($request->reason_maker)
                                    <span class="text-zinc-600">{{ __('ui.placement.field.reason_maker') }}: {{ $request->reason_maker }}</span>
                                @endif
                                @if ($request->type === 'PC_CANCEL_ACTIVE' && auth()->user()->can('placement.review'))
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-button size="sm" wire:click="approveCancelActive({{ $request->id }})">
                                            {{ __('ui.placement.cancel_active.approve') }}
                                        </x-button>
                                        @if ($cancelRejectingId === $request->id)
                                            <x-input wire:model="cancelRejectNote" class="w-56"
                                                label="{{ __('ui.placement.cancel_active.note_label') }}"
                                                placeholder="{{ __('ui.placement.cancel_active.note_placeholder') }}" />
                                            <x-button size="sm" variant="destructive" wire:click="rejectCancelActive({{ $request->id }})">
                                                {{ __('ui.placement.cancel_active.reject_confirm') }}
                                            </x-button>
                                            <x-button size="sm" variant="ghost" wire:click="cancelCancelReject">{{ __('ui.placement.cancel_active.cancel') }}</x-button>
                                        @else
                                            <x-button size="sm" variant="secondary" wire:click="startCancelReject({{ $request->id }})">
                                                {{ __('ui.placement.cancel_active.reject') }}
                                            </x-button>
                                        @endif
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
                <div class="border-b border-zinc-200 px-4 py-3">
                    <h2 class="text-sm font-semibold text-zinc-900">{{ __('ui.placement.field.participants') }}</h2>
                </div>
                <table class="w-full text-left text-sm">
                    <thead class="bg-zinc-100 text-xs uppercase text-zinc-600">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.placement.field.candidate') }}</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.placement.field.status_penempatan') }}</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.placement.field.visa') }}</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.placement.field.tanggal_mulai_kerja') }}</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.placement.field.tanggal_berakhir_kontrak') }}</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.placement.field.catatan') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @forelse ($participants as $participant)
                            @php
                                $hidden = $participant->candidate_anonymized_at !== null || $participant->candidate_deleted_at !== null;
                            @endphp
                            <tr class="hover:bg-zinc-50">
                                <td class="px-4 py-2.5">
                                    @if ($hidden)
                                        <span class="text-zinc-400">-</span>
                                    @else
                                        <span class="font-mono text-xs tabular-nums text-zinc-700">{{ $participant->candidate_nomor_induk ?: '-' }}</span>
                                        <span class="ml-2 font-medium text-zinc-900">{{ $participant->candidate_nama_alphabet ?: '-' }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5">
                                    <x-badge :type="match ($participant->status_penempatan) {
                                        'Bekerja' => 'success',
                                        'Selesai Kontrak' => 'info',
                                        'Mengundurkan Diri' => 'exit',
                                        'Dikeluarkan' => 'danger',
                                        default => 'neutral',
                                    }"
                                        :icon="match ($participant->status_penempatan) {
                                            'Bekerja' => 'check-circle',
                                            'Selesai Kontrak' => 'badge-check',
                                            'Mengundurkan Diri' => 'arrow-uturn-left',
                                            'Dikeluarkan' => 'x-circle',
                                            default => 'dot',
                                        }">
                                        {{ __('ui.placement.participant_status.'.$participant->status_penempatan) }}
                                    </x-badge>
                                </td>
                                <td class="px-4 py-2.5 text-zinc-600">
                                    {{ app()->getLocale() === 'ja' ? ($participant->visa_label_ja ?: '-') : ($participant->visa_label_id ?: '-') }}
                                </td>
                                <td class="px-4 py-2.5 tabular-nums text-zinc-600">{{ $participant->tanggal_mulai_kerja ?: '-' }}</td>
                                <td class="px-4 py-2.5 tabular-nums text-zinc-600">{{ $participant->tanggal_berakhir_kontrak ?: '-' }}</td>
                                <td class="px-4 py-2.5 text-zinc-600">{{ $participant->catatan_alasan ?: '-' }}</td>
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
            </div>

            @can('placement.execute')
                @if ($container->status === 'Aktif')
                    <livewire:placement.placement-batch-panel :containerId="$container->id" :version="$version"
                        wire:key="batch-{{ $container->id }}" />
                    <livewire:placement.placement-force-majeur-panel :containerId="$container->id" :version="$version"
                        wire:key="fm-{{ $container->id }}" />
                @endif
            @endcan
        </div>
    @endif
</div>
