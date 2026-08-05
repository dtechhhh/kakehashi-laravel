@extends('layouts.authenticated')

@section('title', __('ui.jobs.form.title'))

@section('content')
    <livewire:jobs.interview-form :containerId="$interviewContainer ?? null" />
@endsection
