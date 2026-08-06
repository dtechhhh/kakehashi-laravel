@extends('layouts.authenticated')

@section('title', __('ui.jobs.detail_title'))

@section('content')
    <livewire:jobs.interview-detail :containerId="$interviewContainer" />
@endsection
