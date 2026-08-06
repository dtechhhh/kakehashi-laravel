<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>@yield('title', __('ui.app_name')) · Kakehashi</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900">
        <a href="#main-content"
            class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-white focus:px-3 focus:py-2 focus:text-sm focus:shadow-md focus:outline-2 focus:outline-blue-600">{{ __('ui.skip_link') }}</a>

        <header class="border-b border-zinc-200 bg-white">
            <div class="mx-auto flex h-14 max-w-7xl items-center gap-3 px-6">
                <a href="/" class="flex items-center gap-2.5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                    <span class="grid h-8 w-8 place-items-center rounded-md bg-zinc-900 text-base font-bold text-white" aria-hidden="true">架</span>
                    <span class="text-base font-semibold text-zinc-900">{{ __('ui.app_name') }}</span>
                </a>
                <p class="hidden text-xs text-zinc-500 sm:block">{{ __('ui.brand_subtitle') }}</p>

                <form method="POST" action="{{ route('language.switch') }}" class="ml-auto flex items-center rounded-full border border-zinc-300 p-0.5">
                    @csrf
                    <button type="submit" name="locale" value="id"
                        class="rounded-full px-2.5 py-1 text-xs font-semibold transition-colors {{ app()->getLocale() === 'id' ? 'bg-zinc-900 text-white' : 'text-zinc-600 hover:text-zinc-900' }}">{{ __('ui.language.id') }}</button>
                    <button type="submit" name="locale" value="ja"
                        class="rounded-full px-2.5 py-1 text-xs font-semibold transition-colors {{ app()->getLocale() === 'ja' ? 'bg-zinc-900 text-white' : 'text-zinc-600 hover:text-zinc-900' }}">{{ __('ui.language.ja') }}</button>
                </form>
            </div>
        </header>

        <main id="main-content" class="mx-auto max-w-7xl px-6 py-8">
            @yield('content')
        </main>
    </body>
</html>
