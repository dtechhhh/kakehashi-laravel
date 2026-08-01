@props(['name', 'title' => null, 'maxWidth' => 'md'])

@php
    $widths = [
        'sm' => 'max-w-sm',
        'md' => 'max-w-md',
        'lg' => 'max-w-lg',
    ];
@endphp

<div data-modal="{{ $name }}" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true"
    @if ($title) aria-labelledby="{{ $name }}-title" @endif>
    <div class="fixed inset-0 bg-zinc-900/50" data-modal-close="{{ $name }}" aria-hidden="true"></div>
    <div class="fixed inset-0 flex items-center justify-center p-4">
        <div class="w-full {{ $widths[$maxWidth] ?? $widths['md'] }} rounded-lg border border-zinc-200 bg-white shadow-md">
            <div class="flex items-center justify-between gap-2 border-b border-zinc-200 px-4 py-3">
                <h2 id="{{ $name }}-title" class="text-base font-semibold text-zinc-900">{{ $title }}</h2>
                <button type="button" data-modal-close="{{ $name }}"
                    class="rounded-md p-1 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                    aria-label="{{ __('ui.common.close') }}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </div>
            <div class="px-4 py-4">{{ $slot }}</div>
            @isset($footer)
                <div class="flex justify-end gap-2 border-t border-zinc-200 px-4 py-3">{{ $footer }}</div>
            @endisset
        </div>
    </div>
</div>
