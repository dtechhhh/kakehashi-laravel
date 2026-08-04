@props(['label' => null, 'name' => null, 'id' => null, 'options' => [], 'placeholder' => null, 'value' => null, 'error' => null, 'hint' => null])

@php
    $wireModel = $attributes->whereStartsWith('wire:model')->first();
    $generatedId = is_string($wireModel) ? preg_replace('/[^A-Za-z0-9_-]+/', '-', $wireModel) : null;
    $inputId = $id ?? $name ?? (filled($generatedId) ? 'field-'.$generatedId : null);
    $hasError = filled($error);
    $describedBy = $inputId ? trim(
        ($hint && ! $hasError ? $inputId . '-hint ' : '') . ($hasError ? $inputId . '-error' : ''),
    ) : '';
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

    <select @if ($inputId) id="{{ $inputId }}" @endif @if ($name) name="{{ $name }}" @endif
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->merge([
            'class' => 'h-9 w-full rounded-md border bg-white px-3 text-sm text-zinc-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 '
                . ($hasError ? 'border-red-400' : 'border-zinc-300'),
        ]) }}>
        @if ($placeholder)
            <option value="" {{ blank($value) ? 'selected' : '' }}>{{ $placeholder }}</option>
        @endif
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" {{ (string) $value === (string) $optionValue ? 'selected' : '' }}>{{ $optionLabel }}</option>
        @endforeach
    </select>

    @if ($hint && ! $hasError)
        <p id="{{ $inputId }}-hint" class="mt-1 text-xs text-zinc-500">{{ $hint }}</p>
    @endif

    @if ($hasError)
        <p id="{{ $inputId }}-error" class="mt-1 text-xs text-red-600">{{ is_array($error) ? implode(' ', $error) : $error }}</p>
    @endif
</div>
