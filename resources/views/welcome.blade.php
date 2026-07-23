<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'Kakehashi') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="grid min-h-screen place-items-center bg-slate-50 text-slate-900">
        <main class="text-center">
            <h1 class="text-3xl font-semibold">{{ config('app.name', 'Kakehashi') }}</h1>
            <p class="mt-2 text-slate-600">Wave 0 baseline</p>
        </main>
    </body>
</html>
