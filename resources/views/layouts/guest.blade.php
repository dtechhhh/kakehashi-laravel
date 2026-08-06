<!DOCTYPE html>
<html lang="ja">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>@yield('title', __('ui.guest.page_title')) · Kakehashi</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900">
        <header class="border-b border-zinc-200 bg-white">
            <div class="mx-auto flex h-14 max-w-5xl items-center gap-3 px-6">
                <span class="grid h-8 w-8 place-items-center rounded-md bg-zinc-900 text-base font-bold text-white" aria-hidden="true">架</span>
                <span class="text-base font-semibold text-zinc-900">{{ __('ui.guest.brand') }}</span>
                <span class="ml-auto hidden text-xs text-zinc-500 sm:block">{{ __('ui.guest.surface_note') }}</span>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-6 py-8">
            @yield('content')
        </main>
    </body>
</html>
