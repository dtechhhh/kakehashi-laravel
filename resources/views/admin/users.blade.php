@extends('layouts.authenticated')

@section('title', __('ui.admin.users.title'))

@section('content')
    <livewire:admin.user-management />
@endsection
