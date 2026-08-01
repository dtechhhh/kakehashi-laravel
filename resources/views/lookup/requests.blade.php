@extends('layouts.authenticated')

@section('title', __('ui.queue.title'))

@section('content')
    <livewire:lookup.request-queue />
@endsection
