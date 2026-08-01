@extends('layouts.authenticated')

@section('title', __('ui.review.revision_diff_title'))

@section('content')
    <livewire:candidate.revision-diff :revisionId="$candidate" />
@endsection
