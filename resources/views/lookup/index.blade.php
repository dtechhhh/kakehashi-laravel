@extends('layouts.authenticated')

@section('title', __('ui.lookup.title'))

@section('content')
    <livewire:lookup.lookup-index />
@endsection
