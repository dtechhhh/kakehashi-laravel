@extends('layouts.authenticated')

@section('title', __('ui.placement.form.title'))

@section('content')
    <livewire:placement.placement-form :containerId="$placement ?? null" />
@endsection
