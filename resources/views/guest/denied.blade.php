@extends('layouts.guest')

@section('title', __('ui.guest.denied_title'))

@section('content')
    <div class="mx-auto max-w-md rounded-lg border border-zinc-200 bg-white p-8 text-center">
        <h1 class="text-lg font-semibold text-zinc-900">{{ __('ui.guest.denied_title') }}</h1>
        <p class="mt-2 text-sm text-zinc-600">{{ __('ui.guest.denied_message') }}</p>
    </div>
@endsection
