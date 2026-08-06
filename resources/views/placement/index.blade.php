@extends('layouts.authenticated')

@section('title', __('ui.placement.list_title'))

@section('content')
    <livewire:placement.placement-index />
@endsection
