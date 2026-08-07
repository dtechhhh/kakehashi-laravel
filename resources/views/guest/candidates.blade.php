@extends('layouts.guest')

@section('title', __('ui.guest.candidates_title'))

@section('content')
    <div class="mb-6">
        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('ui.guest.container_label') }}</p>
        <h1 class="text-xl font-semibold text-zinc-900">{{ $container['nama_perusahaan'] }}</h1>
        <p class="mt-1 text-sm text-zinc-600">
            {{ __('ui.guest.interview_date') }}: {{ $container['tanggal_wawancara'] }}
            · {{ __('ui.guest.interview_type') }}: {{ __('ui.guest.interview_type_'.$container['jenis_wawancara']) }}
        </p>
    </div>

    <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500">
                <tr>
                    <th class="px-4 py-3">{{ __('ui.guest.col_code') }}</th>
                    <th class="px-4 py-3">{{ __('ui.guest.col_age') }}</th>
                    <th class="px-4 py-3">{{ __('ui.guest.col_gender') }}</th>
                    <th class="px-4 py-3">{{ __('ui.guest.col_nationality') }}</th>
                    <th class="px-4 py-3">{{ __('ui.guest.col_japanese') }}</th>
                    <th class="px-4 py-3">{{ __('ui.guest.col_ssw') }}</th>
                    <th class="px-4 py-3">{{ __('ui.guest.col_field') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($list as $item)
                    <tr>
                        <td class="px-4 py-3">
                            <a href="{{ route('guest.detail', $item->id) }}"
                                class="font-medium text-blue-700 hover:underline">{{ $item->nomor_induk }}</a>
                        </td>
                        <td class="px-4 py-3">{{ $item->umur }}歳</td>
                        <td class="px-4 py-3">{{ $item->jenis_kelamin === 'M' ? '男' : '女' }}</td>
                        <td class="px-4 py-3">{{ $item->kewarganegaraan }}</td>
                        <td class="px-4 py-3">
                            @foreach ($item->japanese_levels as $level)
                                <span class="block">{{ $level['jenis'] }}{{ $level['skor'] ? ' · '.$level['skor'] : '' }}</span>
                            @endforeach
                        </td>
                        <td class="px-4 py-3">{{ implode('、', $item->ssw_qualifications) }}</td>
                        <td class="px-4 py-3">{{ $item->bidang_diminati }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-sm text-zinc-500">{{ __('ui.guest.empty_list') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $list->links('pagination::tailwind') }}
    </div>
@endsection
