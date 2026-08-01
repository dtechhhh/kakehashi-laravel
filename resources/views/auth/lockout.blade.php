@extends('layouts.public')

@section('title', __('ui.auth.lockout.title'))

@section('content')
    <div class="mx-auto max-w-md">
        <div class="rounded-lg border border-zinc-200 bg-white p-6 text-center shadow-sm">
            <svg class="mx-auto h-10 w-10 text-warning-text" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"
                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="8.5" />
                <path d="M12 7.5V12l3 2" />
            </svg>
            <h1 class="mt-3 text-xl font-semibold text-zinc-900">{{ __('ui.auth.lockout.title') }}</h1>
            <p class="mt-1 text-sm text-zinc-600">{{ __('ui.auth.lockout.description') }}</p>
            <p id="lockout-countdown" class="mt-3 text-sm font-medium text-zinc-900 tabular-nums"
                data-template="{{ __('ui.auth.lockout.retry_in') }}"></p>
            <div id="lockout-back" class="mt-4 hidden">
                <x-button variant="secondary" href="{{ route('login.form') }}">{{ __('ui.auth.lockout.back_to_login') }}</x-button>
            </div>
        </div>
    </div>
@endsection
