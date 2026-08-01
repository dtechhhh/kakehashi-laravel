@extends('layouts.public')

@section('title', __('ui.auth.password_forced.title'))

@section('content')
    <div class="mx-auto max-w-md">
        <div class="rounded-lg border border-zinc-200 bg-white p-6 shadow-sm">
            <h1 class="text-xl font-semibold text-zinc-900">{{ __('ui.auth.password_forced.title') }}</h1>
            <p class="mt-1 text-sm text-zinc-600">{{ __('ui.auth.password_forced.subtitle') }}</p>

            <form id="password-change-form" action="{{ route('password.update') }}" method="POST" class="mt-4 flex flex-col gap-4" novalidate>
                @csrf
                <x-input id="password-current" name="current_password" type="password" label="{{ __('ui.common.current_password') }}" required autocomplete="current-password" />
                <x-input id="password-new" name="password" type="password" label="{{ __('ui.common.new_password') }}" required autocomplete="new-password" />
                <x-input id="password-confirm" name="password_confirmation" type="password" label="{{ __('ui.common.confirm_password') }}" required autocomplete="new-password" />
                <p class="text-xs text-zinc-500">{{ __('ui.auth.password_forced.policy') }}</p>
                <p id="password-change-error" role="alert" class="hidden rounded-md bg-danger-bg px-3 py-2 text-sm text-danger-text"></p>
                <x-button type="submit" class="w-full">{{ __('ui.common.confirm') }}</x-button>
            </form>
        </div>

        <p id="password-change-error-current" class="hidden" data-message="{{ __('ui.auth.password_forced.error_current') }}"></p>
        <p id="password-change-error-generic" class="hidden" data-message="{{ __('ui.auth.login.error_generic') }}"></p>
    </div>
@endsection
