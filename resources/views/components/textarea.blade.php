@props(['label' => null, 'name' => null, 'id' => null, 'value' => null, 'rows' => 3, 'error' => null, 'hint' => null])

@php
    $inputId = $id ?? $name;
    $hasError = filled($error);
    $describedBy = trim(
        ($hint && ! $hasError ? $inputId . '-hint ' : '') . ($hasError ? $inputId . '-error' : ''),
    );
@endphp

<div {{ $attributes->only(['class']) }}>
    @if ($label)
        <label for="{{ $inputId }}" class="mb-1 block text-xs font-medium text-zinc-600">
            {{ $label }}
            @if ($attributes->has('required'))
                <span class="text-red-600" aria-hidden="true">*</span>
            @endif
        </label>
    @endif

    <textarea id="{{ $inputId }}" name="{{ $name }}" rows="{{ $rows }}"
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->whereStartsWith('wire:')->merge([
            'class' => 'w-full rounded-md border bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 '
                . ($hasError ? 'border-red-400' : 'border-zinc-300'),
        ]) }}>{{ $value }}</textarea>

    @if ($hint && ! $hasError)
        <p id="{{ $inputId }}-hint" class="mt-1 text-xs text-zinc-500">{{ $hint }}</p>
    @endif

    @if ($hasError)
        <p id="{{ $inputId }}-error" class="mt-1 text-xs text-red-600">{{ is_array($error) ? implode(' ', $error) : $error }}</p>
    @endif
</div>
