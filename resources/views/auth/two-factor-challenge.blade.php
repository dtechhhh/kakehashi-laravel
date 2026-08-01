@extends('layouts.public')

@section('title', __('ui.auth.challenge.title'))

@section('content')
    <div class="mx-auto max-w-md">
        <div class="rounded-lg border border-zinc-200 bg-white p-6 shadow-sm">
            <h1 class="text-xl font-semibold text-zinc-900">{{ __('ui.auth.challenge.title') }}</h1>
            <p class="mt-1 text-sm text-zinc-600">{{ __('ui.auth.challenge.subtitle') }}</p>

            <form id="challenge-form" action="{{ route('two-factor.login.store') }}" method="POST" class="mt-4 flex flex-col gap-4" novalidate>
                @csrf
                <div id="challenge-code">
                    <x-input id="challenge-code-input" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                        label="{{ __('ui.auth.challenge.code_label') }}" required autocomplete="one-time-code" />
                </div>
                <div id="challenge-recovery" class="hidden">
                    <x-input id="challenge-recovery-input" name="recovery_code" type="text" label="{{ __('ui.auth.challenge.recovery_label') }}" autocomplete="one-time-code" />
                </div>
                <p id="challenge-error" role="alert" class="hidden rounded-md bg-danger-bg px-3 py-2 text-sm text-danger-text"></p>
                <x-button type="submit" class="w-full">
                    <svg class="spinner hidden h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"/></svg>
                    {{ __('ui.common.confirm') }}
                </x-button>
                <p class="text-center">
                    <button id="challenge-toggle" type="button" class="text-sm text-blue-600 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                        {{ __('ui.auth.challenge.use_recovery') }}
                    </button>
                </p>
            </form>
        </div>

        <p id="challenge-error-invalid" class="hidden" data-message="{{ __('ui.auth.challenge.error_invalid') }}"></p>
        <p id="challenge-toggle-use-recovery" class="hidden" data-message="{{ __('ui.auth.challenge.use_recovery') }}"></p>
        <p id="challenge-toggle-use-code" class="hidden" data-message="{{ __('ui.auth.challenge.use_code') }}"></p>
    </div>
@endsection
