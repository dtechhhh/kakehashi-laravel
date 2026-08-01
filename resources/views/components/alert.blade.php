@props(['type' => 'info'])

@php
    $tones = [
        'success' => ['bg-success-bg text-success-text', '<circle cx="12" cy="12" r="8.5"/><path d="M8.5 12.5l2.5 2.5 5-5.5"/>'],
        'error' => ['bg-danger-bg text-danger-text', '<circle cx="12" cy="12" r="8.5"/><path d="M9 9l6 6M15 9l-6 6"/>'],
        'info' => ['bg-info-bg text-info-text', '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>'],
        'warning' => ['bg-warning-bg text-warning-text', '<path d="M12 4l9 16H3L12 4z"/><path d="M12 10v4"/><path d="M12 17h.01"/>'],
    ];

    $tone = $tones[$type] ?? $tones['info'];
@endphp

<div role="alert"
    {{ $attributes->merge(['class' => 'flex items-start gap-2 rounded-md px-3 py-2 text-sm ' . $tone[0]]) }}>
    <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $tone[1] !!}</svg>
    <div class="min-w-0 flex-1">{{ $slot }}</div>
</div>
