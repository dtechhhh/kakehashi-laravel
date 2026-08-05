<div>
    @if ($notFound)
        <x-state type="not-found" title="{{ __('ui.jobs.not_found.title') }}"
            description="{{ __('ui.jobs.not_found.description') }}" />
    @else
        <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold text-zinc-900">{{ $container->judul }}</h1>
                    <p class="mt-1 font-mono text-xs tabular-nums text-zinc-500">{{ $container->kode_kontainer ?: '-' }}</p>
                </div>
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
            </div>

            @if ($container->status === 'Ditutup')
                <div class="rounded-lg border border-zinc-200 bg-neutral-bg px-4 py-3 text-sm text-neutral-text">
                    {{ __('ui.jobs.closed_banner') }}
                </div>
            @endif

            @if ($targetExceeded)
                <div class="rounded-lg border border-amber-200 bg-warning-bg px-4 py-3 text-sm text-warning-text">
                    {{ __('ui.jobs.target_warning', ['accepted' => $acceptedCount, 'target' => $container->target_peserta_diterima]) }}
                </div>
            @endif

            <div class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm md:grid-cols-3">
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('ui.jobs.field.perusahaan') }}</dt>
                        <dd class="mt-0.5 font-medium text-zinc-900">{{ $container->perusahaan_nama_ja ?: '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('ui.jobs.field.posisi_pekerjaan') }}</dt>
                        <dd class="mt-0.5 font-medium text-zinc-900">
                            {{ app()->getLocale() === 'ja' ? ($container->posisi_label_ja ?: '-') : ($container->posisi_label_id ?: '-') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('ui.jobs.field.jenis_wawancara') }}</dt>
                        <dd class="mt-0.5 font-medium text-zinc-900">{{ __('ui.jobs.jenis_wawancara.'.$container->jenis_wawancara) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('ui.jobs.field.jenis_visa') }}</dt>
                        <dd class="mt-0.5 font-medium text-zinc-900">
                            {{ app()->getLocale() === 'ja' ? ($container->visa_label_ja ?: '-') : ($container->visa_label_id ?: '-') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('ui.jobs.field.tanggal_wawancara') }}</dt>
                        <dd class="mt-0.5 tabular-nums text-zinc-900">
                            {{ $container->tanggal_wawancara ? \Illuminate\Support\Carbon::parse($container->tanggal_wawancara)->format(__('ui.date_time_format')) : '-' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('ui.jobs.field.jumlah_peserta') }}</dt>
                        <dd class="mt-0.5 tabular-nums text-zinc-900">{{ $container->jumlah_peserta }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('ui.jobs.field.target_peserta_diterima') }}</dt>
                        <dd class="mt-0.5 tabular-nums text-zinc-900">{{ $container->target_peserta_diterima ?? '-' }}</dd>
                    </div>
                    @if ($container->deskripsi)
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('ui.jobs.field.deskripsi') }}</dt>
                            <dd class="mt-0.5 whitespace-pre-wrap text-zinc-900">{{ $container->deskripsi }}</dd>
                        </div>
                    @endif
                    @if ($container->syarat)
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('ui.jobs.field.syarat') }}</dt>
                            <dd class="mt-0.5 whitespace-pre-wrap text-zinc-900">{{ $container->syarat }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            @if ($pending->isNotEmpty())
                <div class="rounded-lg border border-amber-200 bg-warning-bg p-4">
                    <h2 class="text-sm font-semibold text-warning-text">{{ __('ui.jobs.pending.title') }}</h2>
                    <ul class="mt-2 space-y-2 text-sm">
                        @foreach ($pending as $request)
                            <li class="flex flex-wrap items-center gap-2">
                                <x-badge type="warning" icon="clock">{{ __('ui.jobs.pending.'.$request->type) }}</x-badge>
                                <span class="text-zinc-700">{{ __('ui.jobs.field.created_at') }}: {{ \Illuminate\Support\Carbon::parse($request->created_at)->format(__('ui.date_time_format')) }}</span>
                                @if ($request->reason_maker)
                                    <span class="text-zinc-600">{{ __('ui.jobs.field.reason_maker') }}: {{ $request->reason_maker }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
                <div class="border-b border-zinc-200 px-4 py-3">
                    <h2 class="text-sm font-semibold text-zinc-900">{{ __('ui.jobs.field.participations') }}</h2>
                </div>
                <table class="w-full text-left text-sm">
                    <thead class="bg-zinc-100 text-xs uppercase text-zinc-600">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.jobs.field.candidate') }}</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.jobs.field.status_wawancara') }}</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.jobs.field.catatan') }}</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.jobs.field.updated_at') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @forelse ($participations as $participation)
                            @php
                                $hidden = $participation->candidate_anonymized_at !== null || $participation->candidate_deleted_at !== null;
                            @endphp
                            <tr class="hover:bg-zinc-50 {{ $participation->frozen_at !== null ? 'bg-zinc-50' : '' }}">
                                <td class="px-4 py-2.5">
                                    @if ($hidden)
                                        <span class="text-zinc-400">-</span>
                                    @else
                                        <span class="font-mono text-xs tabular-nums text-zinc-700">{{ $participation->candidate_nomor_induk ?: '-' }}</span>
                                        <span class="ml-2 font-medium text-zinc-900">{{ $participation->candidate_nama_alphabet ?: '-' }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <x-badge :type="match ($participation->status_wawancara) {
                                            'Menunggu Wawancara' => 'warning',
                                            'Lulus' => 'success',
                                            'Proses Dokumen', 'Siap Dikirim' => 'info',
                                            'Terkirim' => 'accent2',
                                            'Tidak Lolos', 'Dikeluarkan' => 'danger',
                                            'Mengundurkan Diri' => 'exit',
                                            default => 'neutral',
                                        }"
                                            :icon="match ($participation->status_wawancara) {
                                                'Menunggu Wawancara', 'Proses Dokumen' => 'clock',
                                                'Lulus', 'Siap Dikirim' => 'check-circle',
                                                'Terkirim' => 'badge-check',
                                                'Tidak Lolos', 'Dikeluarkan' => 'x-circle',
                                                'Mengundurkan Diri' => 'arrow-uturn-left',
                                                default => 'dot',
                                            }">
                                            {{ __('ui.jobs.participation_status.'.$participation->status_wawancara) }}
                                        </x-badge>
                                        @if ($participation->frozen_at !== null)
                                            <x-badge type="neutral" icon="lock">{{ __('ui.jobs.frozen_badge') }}</x-badge>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-zinc-600">{{ $participation->catatan ?: '-' }}</td>
                                <td class="px-4 py-2.5 tabular-nums text-zinc-600">{{ \Illuminate\Support\Carbon::parse($participation->updated_at)->format(__('ui.date_time_format')) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-10">
                                    <x-state type="empty" class="!border-0 !shadow-none" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
