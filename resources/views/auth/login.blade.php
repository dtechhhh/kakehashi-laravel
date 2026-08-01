@extends('layouts.public')

@section('title', __('ui.auth.login.title'))

@section('content')
    <div class="mx-auto max-w-md">
        <div class="rounded-lg border border-zinc-200 bg-white p-6 shadow-sm">
            <h1 class="text-xl font-semibold text-zinc-900">{{ __('ui.auth.login.title') }}</h1>
            <p class="mt-1 text-sm text-zinc-600">{{ __('ui.auth.login.subtitle') }}</p>

            <form id="login-form" action="{{ route('login') }}" method="POST" class="mt-4 flex flex-col gap-4" novalidate>
                @csrf
                <x-input id="login-email" name="email" type="email" label="{{ __('ui.common.email') }}" required autocomplete="email" />
                <x-input id="login-password" name="password" type="password" label="{{ __('ui.common.password') }}" required autocomplete="current-password" />
                <p id="login-error" role="alert" class="hidden rounded-md bg-danger-bg px-3 py-2 text-sm text-danger-text"></p>
                <x-button type="submit" class="w-full">
                    <svg class="spinner hidden h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"/></svg>
                    {{ __('ui.common.login') }}
                </x-button>
            </form>
        </div>

        <p id="login-error-invalid" class="hidden" data-message="{{ __('ui.auth.login.error_invalid') }}"></p>
        <p id="login-error-inactive" class="hidden" data-message="{{ __('ui.auth.login.error_inactive') }}"></p>
        <p id="login-error-generic" class="hidden" data-message="{{ __('ui.auth.login.error_generic') }}"></p>
    </div>
@endsection
