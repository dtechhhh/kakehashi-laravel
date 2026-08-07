@extends('layouts.guest')

@section('title', __('ui.guest.code_title'))

@section('content')
    <div class="mx-auto max-w-md rounded-lg border border-zinc-200 bg-white p-8">
        <h1 class="text-lg font-semibold text-zinc-900">{{ __('ui.guest.code_title') }}</h1>
        <p class="mt-1 text-sm text-zinc-600">{{ __('ui.guest.code_hint') }}</p>

        <form method="POST" action="{{ route('guest.code', $token) }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="code" class="block text-sm font-medium text-zinc-700">{{ __('ui.guest.code_label') }}</label>
                <input id="code" name="code" type="text" autocomplete="off" required
                    class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:outline-2 focus:outline-blue-600">
            </div>
            <button type="submit"
                class="w-full rounded-md bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                {{ __('ui.guest.open_link') }}
            </button>
        </form>
    </div>
@endsection
