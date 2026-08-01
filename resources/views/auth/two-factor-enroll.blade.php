@extends('layouts.public')

@section('title', __('ui.auth.enroll.title'))

@section('content')
    <div id="enroll-page" class="mx-auto max-w-md">
        <div class="rounded-lg border border-zinc-200 bg-white p-6 shadow-sm">
            <h1 class="text-xl font-semibold text-zinc-900">{{ __('ui.auth.enroll.title') }}</h1>
            <p class="mt-1 text-sm text-zinc-600">{{ __('ui.auth.enroll.subtitle') }}</p>

            <div id="enroll-loading" class="mt-6 flex justify-center py-8" role="status">
                <svg class="h-8 w-8 animate-spin text-zinc-400" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"/></svg>
                <span class="sr-only">{{ __('ui.state.loading') }}</span>
            </div>

            <div id="enroll-already" class="mt-6 hidden">
                <p class="rounded-md bg-success-bg px-3 py-2 text-sm text-success-text">{{ __('ui.auth.enroll.already_enabled') }}</p>
                <x-button variant="primary" href="{{ route('home') }}" class="mt-4 w-full">{{ __('ui.auth.enroll.continue_home') }}</x-button>
            </div>

            <div id="enroll-stage" class="mt-6 hidden">
                <p class="text-sm font-medium text-zinc-900">{{ __('ui.auth.enroll.step_scan') }}</p>
                <div id="enroll-qr" class="mx-auto mt-3 w-fit rounded-md border border-zinc-200 bg-white p-3"></div>
                <p class="mt-2 text-center text-xs text-zinc-500">{{ __('ui.auth.enroll.secret_label') }}</p>
                <p id="enroll-secret" class="mt-1 text-center font-mono text-xs tabular-nums text-zinc-700"></p>

                <p class="mt-6 text-sm font-medium text-zinc-900">{{ __('ui.auth.enroll.step_confirm') }}</p>
                <form id="enroll-confirm-form" method="POST" class="mt-3 flex flex-col gap-4" novalidate>
                    @csrf
                    <x-input id="enroll-code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                        label="{{ __('ui.auth.challenge.code_label') }}" required autocomplete="one-time-code" />
                    <p id="enroll-error" role="alert" class="hidden rounded-md bg-danger-bg px-3 py-2 text-sm text-danger-text"></p>
                    <x-button type="submit" class="w-full">
                        <svg class="spinner hidden h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"/></svg>
                        {{ __('ui.auth.enroll.confirm_button') }}
                    </x-button>
                </form>
            </div>

            <div id="enroll-recovery" class="mt-6 hidden">
                <h2 class="text-base font-semibold text-zinc-900">{{ __('ui.auth.enroll.recovery_title') }}</h2>
                <p class="mt-1 text-sm text-zinc-600">{{ __('ui.auth.enroll.recovery_description') }}</p>
                <ul id="enroll-recovery-codes" class="mt-3 grid grid-cols-2 gap-2"></ul>
                <p class="mt-3 rounded-md bg-warning-bg px-3 py-2 text-sm text-warning-text">{{ __('ui.auth.enroll.recovery_done') }}</p>
                <x-button variant="primary" href="{{ route('home') }}" class="mt-4 w-full">{{ __('ui.auth.enroll.continue_home') }}</x-button>
            </div>
        </div>

        <p id="enroll-error-invalid" class="hidden" data-message="{{ __('ui.auth.enroll.error_invalid_code') }}"></p>
        <p id="enroll-error-generic" class="hidden" data-message="{{ __('ui.auth.enroll.error_generic') }}"></p>
    </div>
@endsection
