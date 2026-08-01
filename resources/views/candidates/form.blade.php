@extends('layouts.authenticated')

@section('title', $candidate ?? null ? __('ui.form.edit_title') : __('ui.form.create_title'))

@section('content')
    <livewire:candidate.candidate-form :candidate="$candidate ?? null" />
@endsection
