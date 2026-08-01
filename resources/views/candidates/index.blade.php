@extends('layouts.authenticated')

@section('title', __('ui.candidate.list_title'))

@section('content')
    <livewire:candidate.candidate-index />
@endsection
