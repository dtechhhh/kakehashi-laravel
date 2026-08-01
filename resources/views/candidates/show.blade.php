@extends('layouts.authenticated')

@section('title', __('ui.candidate.detail_title'))

@section('content')
    <livewire:candidate.candidate-detail :candidateId="$candidate" />
@endsection
