@props(['type' => 'empty', 'title' => null, 'description' => null])

@php
    $content = [
        'empty' => [
            'title' => __('ui.state.empty.title'),
            'description' => __('ui.state.empty.description'),
            'icon' => '<path d="M4 7h16v12H4z"/><path d="M4 11h16"/><path d="M9 15h6"/>',
            'tone' => 'text-zinc-400',
        ],
        'forbidden' => [
            'title' => __('ui.state.forbidden.title'),
            'description' => __('ui.state.forbidden.description'),
            'icon' => '<circle cx="12" cy="12" r="8.5"/><path d="M9 9l6 6M15 9l-6 6"/>',
            'tone' => 'text-danger-text',
        ],
        'not-found' => [
            'title' => __('ui.state.not_found.title'),
            'description' => __('ui.state.not_found.description'),
            'icon' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/>',
            'tone' => 'text-zinc-400',
        ],
        'session-expired' => [
            'title' => __('ui.state.session_expired.title'),
            'description' => __('ui.state.session_expired.description'),
            'icon' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
            'tone' => 'text-warning-text',
        ],
        'conflict' => [
            'title' => __('ui.state.conflict.title'),
            'description' => __('ui.state.conflict.description'),
            'icon' => '<path d="M4 7h9a5 5 0 0 1 0 10H8"/><path d="M8 10l-3 3 3 3"/>',
            'tone' => 'text-warning-text',
        ],
        'loading' => [
            'title' => __('ui.state.loading'),
            'description' => null,
            'icon' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 3.5a8.5 8.5 0 0 1 8.5 8.5"/>',
            'tone' => 'text-zinc-400',
        ],
    ];

    $c = $content[$type] ?? $content['empty'];
@endphp

<div role="{{ $type === 'loading' ? 'status' : 'region' }}"
    {{ $attributes->merge(['class' => 'mx-auto flex max-w-md flex-col items-center gap-2 rounded-lg border border-zinc-200 bg-white px-6 py-10 text-center shadow-sm']) }}>
    <svg class="{{ $c['tone'] }} h-10 w-10 {{ $type === 'loading' ? 'animate-spin' : '' }}" viewBox="0 0 24 24" fill="none"
        stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"
        aria-hidden="true">{!! $c['icon'] !!}</svg>
    <h2 class="text-base font-semibold text-zinc-900">{{ $title ?? $c['title'] }}</h2>
    @if ($description ?? $c['description'])
        <p class="text-sm text-zinc-600">{{ $description ?? $c['description'] }}</p>
    @endif
    @if (! $slot->isEmpty())
        <div class="mt-2 flex flex-wrap justify-center gap-2">
            @if ($type === 'conflict')
                <x-button variant="primary" onclick="window.location.reload()">{{ __('ui.common.reload') }}</x-button>
            @endif
            {{ $slot }}
        </div>
    @endif
</div>
