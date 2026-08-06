@extends('layouts.authenticated')

@section('title', __('ui.placement.detail_title'))

@section('content')
    <livewire:placement.placement-detail :containerId="$placement" />
@endsection
