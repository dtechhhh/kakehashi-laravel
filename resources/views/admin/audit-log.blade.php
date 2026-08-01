@extends('layouts.authenticated')

@section('title', __('ui.admin.audit.title'))

@section('content')
    <livewire:admin.audit-log-viewer />
@endsection
