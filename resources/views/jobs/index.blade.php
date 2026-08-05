@extends('layouts.authenticated')

@section('title', __('ui.jobs.list_title'))

@section('content')
    <livewire:jobs.interview-index />
@endsection
