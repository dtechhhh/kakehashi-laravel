@props(['variant' => 'primary', 'size' => 'md', 'type' => 'button', 'href' => null])

@php
    $variants = [
        'primary' => 'bg-zinc-900 text-white hover:bg-zinc-800',
        'secondary' => 'border border-zinc-300 bg-white text-zinc-900 hover:bg-zinc-50',
        'destructive' => 'bg-red-600 text-white hover:bg-red-700',
        'ghost' => 'text-zinc-700 hover:bg-zinc-100',
    ];

    $sizes = [
        'sm' => 'h-8 px-3 text-sm',
        'md' => 'h-9 px-4 text-sm',
    ];

    $class = 'inline-flex items-center justify-center gap-1.5 rounded-md font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:pointer-events-none disabled:opacity-50 '
        . ($variants[$variant] ?? $variants['primary']) . ' ' . ($sizes[$size] ?? $sizes['md']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $class]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $class]) }}>{{ $slot }}</button>
@endif
