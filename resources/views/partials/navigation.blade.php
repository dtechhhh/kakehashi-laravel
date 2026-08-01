@php
    /** @var array<int, array{label: string, route: string, ability: string|null, icon: string}> $items */
    $items = collect(config('navigation.items'))
        ->filter(fn (array $item): bool => Route::has($item['route'])
            && ($item['ability'] === null || auth()->user()?->can($item['ability'])))
        ->values()
        ->all();

    $icons = [
        'home' => '<path d="M4 10.5 12 4l8 6.5"/><path d="M6 9.5V20h12V9.5"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3.5 19c.5-3 2.5-4.5 5.5-4.5s5 1.5 5.5 4.5"/><path d="M16 5.5a2.5 2.5 0 0 1 0 5"/><path d="M17.5 14.5c1.5.6 2.5 2 3 4.5"/>',
        'list' => '<path d="M8.5 6h12M8.5 12h12M8.5 18h12"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
        'inbox' => '<path d="M4 12h4l2 3h4l2-3h4"/><path d="M4 12V5h16v7"/><path d="M5 12h3l1.5 2.5h5L16 12h3"/>',
        'building' => '<rect x="5" y="4" width="14" height="16"/><path d="M9 8h2M13 8h2M9 12h2M13 12h2M9 16h2M13 16h2"/><path d="M10 20v-3h4v3"/>',
        'file' => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/><path d="M9 12h6M9 16h6"/>',
    ];
@endphp

<nav class="border-b border-zinc-200 bg-white" aria-label="{{ __('ui.nav.label') }}">
    <div class="mx-auto flex max-w-7xl items-center gap-1 overflow-x-auto px-6">
        @foreach ($items as $item)
            <a href="{{ route($item['route']) }}"
                class="{{ request()->routeIs($item['route']) ? 'border-zinc-900 font-semibold text-zinc-900' : 'border-transparent text-zinc-600 hover:text-zinc-900' }} -mb-px flex h-10 shrink-0 items-center gap-1.5 border-b-2 px-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons[$item['icon']] !!}</svg>
                <span>{{ __($item['label']) }}</span>
            </a>
        @endforeach
    </div>
</nav>
