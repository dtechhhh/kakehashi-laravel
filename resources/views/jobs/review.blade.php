@extends('layouts.authenticated')

@section('title', __('ui.jobs.queue.title'))

@section('content')
    <livewire:jobs.interview-review-queue />
@endsection
