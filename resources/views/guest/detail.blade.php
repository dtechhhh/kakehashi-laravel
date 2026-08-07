@extends('layouts.guest')

@section('title', __('ui.guest.detail_title'))

@section('content')
    <a href="{{ route('guest.candidates') }}" class="text-sm text-blue-700 hover:underline">← {{ __('ui.guest.back_to_list') }}</a>

    <div class="mt-4 rounded-lg border border-zinc-200 bg-white p-6">
        <h1 class="text-xl font-semibold text-zinc-900">{{ $detail['nomor_induk'] }}</h1>
        <p class="mt-1 text-sm text-zinc-600">{{ $detail['nama_alphabet'] }}{{ $detail['nama_katakana'] ? ' / '.$detail['nama_katakana'] : '' }}</p>
    </div>

    <div class="mt-4 rounded-lg border border-zinc-200 bg-white p-6">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">{{ __('ui.guest.detail_profile') }}</h2>
        <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div><dt class="text-zinc-500">{{ __('ui.guest.col_age') }}</dt><dd class="font-medium">{{ $detail['umur'] }}歳</dd></div>
            <div><dt class="text-zinc-500">{{ __('ui.guest.col_gender') }}</dt><dd class="font-medium">{{ $detail['jenis_kelamin'] === 'M' ? '男' : '女' }}</dd></div>
            <div><dt class="text-zinc-500">{{ __('ui.guest.col_nationality') }}</dt><dd class="font-medium">{{ $detail['kewarganegaraan'] }}</dd></div>
            <div><dt class="text-zinc-500">{{ __('ui.guest.col_field') }}</dt><dd class="font-medium">{{ $detail['bidang_diminati'] }}</dd></div>
        </dl>
    </div>

    <div class="mt-4 rounded-lg border border-zinc-200 bg-white p-6">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">{{ __('ui.guest.detail_photo') }}</h2>
        @if ($detail['photo_available'])
            <img src="{{ route('guest.photo', $detail['id']) }}" alt="{{ $detail['nomor_induk'] }}"
                class="mt-3 h-48 w-36 rounded-md border border-zinc-200 object-cover">
        @else
            <p class="mt-2 text-sm text-zinc-500">{{ __('ui.guest.no_photo') }}</p>
        @endif
    </div>

    <div class="mt-4 rounded-lg border border-zinc-200 bg-white p-6">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">{{ __('ui.guest.detail_qualifications') }}</h2>
        <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-zinc-500">{{ __('ui.guest.detail_japanese') }}</dt>
                <dd class="font-medium">
                    @forelse ($detail['japanese_levels'] as $level)
                        <span class="block">{{ $level['jenis'] }}{{ $level['skor'] ? ' · '.$level['skor'] : '' }}</span>
                    @empty
                        —
                    @endforelse
                </dd>
            </div>
            <div>
                <dt class="text-zinc-500">{{ __('ui.guest.detail_english') }}</dt>
                <dd class="font-medium">
                    @forelse ($detail['english_levels'] as $level)
                        <span class="block">{{ $level['jenis'] }}{{ $level['skor'] ? ' · '.$level['skor'] : '' }}</span>
                    @empty
                        —
                    @endforelse
                </dd>
            </div>
            <div>
                <dt class="text-zinc-500">{{ __('ui.guest.detail_driving') }}</dt>
                <dd class="font-medium">{{ implode('、', $detail['driving_qualifications']) ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500">{{ __('ui.guest.detail_ssw') }}</dt>
                <dd class="font-medium">{{ implode('、', $detail['ssw_qualifications']) ?: '—' }}</dd>
            </div>
        </dl>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="rounded-lg border border-zinc-200 bg-white p-6">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">{{ __('ui.guest.detail_work') }}</h2>
            @forelse ($detail['work_history'] as $work)
                <div class="mt-3 text-sm">
                    <p class="font-medium">{{ $work['nama_perusahaan'] }}</p>
                    <p class="text-zinc-600">{{ $work['bidang_pekerjaan'] }}{{ $work['perusahaan_penanggung'] ? '（'.$work['perusahaan_penanggung'].'）' : '' }}</p>
                    <p class="text-xs text-zinc-500">{{ $work['tanggal_masuk'] }} 〜 {{ $work['tanggal_keluar'] }}</p>
                </div>
            @empty
                <p class="mt-2 text-sm text-zinc-500">—</p>
            @endforelse
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-6">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">{{ __('ui.guest.detail_education') }}</h2>
            @forelse ($detail['education_history'] as $edu)
                <div class="mt-3 text-sm">
                    <p class="font-medium">{{ $edu['nama_institusi'] }}</p>
                    <p class="text-zinc-600">{{ $edu['jenis_pendidikan'] }}{{ $edu['jurusan'] ? ' / '.$edu['jurusan'] : '' }}</p>
                    <p class="text-xs text-zinc-500">{{ $edu['tanggal_masuk'] }} 〜 {{ $edu['tanggal_keluar'] }}</p>
                </div>
            @empty
                <p class="mt-2 text-sm text-zinc-500">—</p>
            @endforelse
        </div>
    </div>

    @if ($detail['shareable_documents'] !== [])
        <div class="mt-4 rounded-lg border border-zinc-200 bg-white p-6">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">{{ __('ui.guest.detail_documents') }}</h2>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($detail['shareable_documents'] as $document)
                    <li>
                        <a href="{{ $document['url'] }}" target="_blank" rel="noopener"
                            class="text-blue-700 hover:underline">{{ $document['jenis'] }}</a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
