<div class="relative" wire:poll.5s="refresh">
    <button type="button" wire:click="toggle"
        class="relative rounded-md p-2 text-zinc-600 hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
        aria-label="{{ __('ui.notifications.title') }}" aria-expanded="{{ $open ? 'true' : 'false' }}">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M6 9a6 6 0 0 1 12 0c0 5 2 6.5 2 6.5H4S6 14 6 9z" />
            <path d="M10 19a2 2 0 0 0 4 0" />
        </svg>
        @if ($unreadCount > 0)
            <span class="absolute -right-0.5 -top-0.5 grid min-w-4 place-items-center rounded-full bg-red-600 px-1 text-[10px] font-semibold leading-4 text-white"
                aria-label="{{ __('ui.notifications.unread', ['count' => $unreadCount]) }}">{{ min($unreadCount, 99) }}</span>
        @endif
    </button>

    @if ($open)
        <div class="absolute right-0 z-50 mt-1 w-80 rounded-lg border border-zinc-200 bg-white shadow-md" role="menu"
            aria-label="{{ __('ui.notifications.title') }}">
            <div class="border-b border-zinc-200 px-3 py-2 text-xs font-semibold text-zinc-700">{{ __('ui.notifications.title') }}</div>
            <ul class="max-h-80 overflow-auto">
                @forelse ($items as $item)
                    <li class="border-b border-zinc-100 px-3 py-2">
                        <p class="text-sm {{ $item['read'] ? 'text-zinc-500' : 'font-medium text-zinc-900' }}">{{ $item['label'] }}</p>
                        <p class="mt-0.5 text-xs tabular-nums text-zinc-400">{{ $item['time'] }}</p>
                    </li>
                @empty
                    <li class="px-3 py-6 text-center text-sm text-zinc-500">{{ __('ui.notifications.empty') }}</li>
                @endforelse
            </ul>
        </div>
    @endif
</div>
