@extends('layouts.authenticated')

@section('title', __('ui.review.title'))

@section('content')
    <livewire:candidate.review-queue />
@endsection
