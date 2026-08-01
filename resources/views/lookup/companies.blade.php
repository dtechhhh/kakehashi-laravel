@extends('layouts.authenticated')

@section('title', __('ui.company.title'))

@section('content')
    <livewire:lookup.company-master />
@endsection
