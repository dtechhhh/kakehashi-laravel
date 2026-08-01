<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>@yield('title', __('ui.app_name')) · Kakehashi</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900">
        <a href="#main-content"
            class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-white focus:px-3 focus:py-2 focus:text-sm focus:shadow-md focus:outline-2 focus:outline-blue-600">{{ __('ui.skip_link') }}</a>

        <header class="sticky top-0 z-40 border-b border-zinc-200 bg-white">
            <div class="mx-auto flex h-14 max-w-7xl items-center gap-3 px-6">
                <a href="{{ route('home') }}" class="flex items-center gap-2.5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                    <span class="grid h-8 w-8 place-items-center rounded-md bg-zinc-900 text-base font-bold text-white" aria-hidden="true">架</span>
                    <span class="text-base font-semibold text-zinc-900">{{ __('ui.app_name') }}</span>
                </a>
                <p class="hidden text-xs text-zinc-500 lg:block">{{ __('ui.brand_subtitle') }}</p>

                <div class="ml-auto flex items-center gap-2">
                    <form method="POST" action="{{ route('language.switch') }}" class="flex items-center rounded-full border border-zinc-300 p-0.5">
                        @csrf
                        <button type="submit" name="locale" value="id"
                            class="rounded-full px-2.5 py-1 text-xs font-semibold transition-colors {{ app()->getLocale() === 'id' ? 'bg-zinc-900 text-white' : 'text-zinc-600 hover:text-zinc-900' }}">{{ __('ui.language.id') }}</button>
                        <button type="submit" name="locale" value="ja"
                            class="rounded-full px-2.5 py-1 text-xs font-semibold transition-colors {{ app()->getLocale() === 'ja' ? 'bg-zinc-900 text-white' : 'text-zinc-600 hover:text-zinc-900' }}">{{ __('ui.language.ja') }}</button>
                    </form>

                    <livewire:shell.notification-bell />

                    <details class="group relative">
                        <summary class="flex cursor-pointer list-none items-center gap-2 rounded-md p-1.5 hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                            aria-label="{{ __('ui.user_menu.label') }}">
                            <span class="grid h-7 w-7 place-items-center rounded-full bg-zinc-200 text-xs font-semibold text-zinc-700"
                                aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                            <span class="hidden text-left md:block">
                                <span class="block max-w-40 truncate text-sm font-medium text-zinc-900">{{ auth()->user()->name }}</span>
                                <span class="block max-w-40 truncate text-xs text-zinc-500">{{ auth()->user()->roles->pluck('name')->join(', ') }}</span>
                            </span>
                            <svg class="h-3.5 w-3.5 text-zinc-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                        </summary>
                        <div class="absolute right-0 z-40 mt-1 w-56 rounded-lg border border-zinc-200 bg-white py-1 shadow-md">
                            <p class="border-b border-zinc-100 px-3 py-2 text-xs text-zinc-500">{{ auth()->user()->email }}</p>
                            <form method="POST" action="{{ route('logout') }}" class="px-1 py-1">
                                @csrf
                                <button type="submit" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm text-zinc-700 hover:bg-zinc-100">{{ __('ui.common.logout') }}</button>
                            </form>
                        </div>
                    </details>
                </div>
            </div>
        </header>

        @include('partials.navigation')

        <main id="main-content" class="mx-auto max-w-7xl px-6 py-8">
            @if (session('status'))
                <x-alert type="success" class="mb-4">{{ session('status') }}</x-alert>
            @endif
            @if (session('error'))
                <x-alert type="error" class="mb-4">{{ session('error') }}</x-alert>
            @endif
            @yield('content')
        </main>

        @livewireScripts
    </body>
</html>
