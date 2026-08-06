@extends('layouts.authenticated')

@section('title', __('ui.placement.queue.title'))

@section('content')
    <livewire:placement.placement-review-queue />
@endsection
