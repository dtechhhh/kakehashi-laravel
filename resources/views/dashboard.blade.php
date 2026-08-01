@extends('layouts.authenticated')

@section('title', __('ui.nav.home'))

@section('content')
    <div class="flex flex-col gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900">{{ __('ui.nav.home') }}</h1>
            <p class="mt-1 text-sm text-zinc-600">{{ __('ui.home.greeting', ['name' => auth()->user()->name]) }}</p>
        </div>

        <x-state type="empty" :title="__('ui.home.empty_title')" :description="__('ui.home.empty_description')" />
    </div>
@endsection
