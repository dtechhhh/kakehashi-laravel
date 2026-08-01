@props(['type' => 'neutral', 'icon' => 'dot'])

@php
    $palette = [
        'neutral' => 'bg-neutral-bg text-neutral-text',
        'success' => 'bg-success-bg text-success-text',
        'warning' => 'bg-warning-bg text-warning-text',
        'info' => 'bg-info-bg text-info-text',
        'danger' => 'bg-danger-bg text-danger-text',
        'accent2' => 'bg-accent2-bg text-accent2-text',
        'exit' => 'bg-exit-bg text-exit-text',
    ];

    $icons = [
        'dot' => '<circle cx="12" cy="12" r="4"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'check' => '<path d="M4.5 12.5l5 5 10-10"/>',
        'check-circle' => '<circle cx="12" cy="12" r="8.5"/><path d="M8.5 12.5l2.5 2.5 5-5.5"/>',
        'x-circle' => '<circle cx="12" cy="12" r="8.5"/><path d="M9 9l6 6M15 9l-6 6"/>',
        'arrow-uturn-left' => '<path d="M8 9l-3 3 3 3"/><path d="M5 12h8a4 4 0 0 0 0-8h-3"/>',
        'badge-check' => '<path d="M12 3l7 2.5v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9v-5L12 3z"/><path d="M9 12l2 2 4-4.5"/>',
        'lock' => '<rect x="5.5" y="11" width="13" height="9" rx="1.5"/><path d="M8.5 11V8.5a3.5 3.5 0 0 1 7 0V11"/>',
    ];

    $tone = $palette[$type] ?? $palette['neutral'];
    $glyph = $icons[$icon] ?? $icons['dot'];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ' . $tone]) }}>
    <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $glyph !!}</svg>
    <span>{{ $slot }}</span>
</span>
