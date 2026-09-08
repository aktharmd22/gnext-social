@props([
    'label',
    'name',
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'required' => false,
    'autocomplete' => null,
    'autofocus' => false,
])

@php
    $id = $attributes->get('id', $name);
    $hasError = $errors->has($name);
@endphp

<div {{ $attributes->only('class')->merge(['class' => 'space-y-1.5']) }}>
    <label for="{{ $id }}" class="block text-small font-semibold text-ink-900">
        {{ $label }}
        @unless ($required)
            <span class="ml-1 font-normal text-ink-500">optional</span>
        @endunless
    </label>

    @if (isset($control))
        {{ $control }}
    @else
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="{{ $type }}"
            value="{{ old($name, $value) }}"
            @if ($required) required @endif
            @if ($autofocus) autofocus @endif
            @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
            @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            {{ $attributes->except(['class', 'id']) }}
            class="w-full rounded-control border bg-white px-3 py-2 text-body text-ink-900
                   placeholder:text-ink-500 focus:outline-none
                   {{ $hasError
                       ? 'border-failed focus:border-failed'
                       : 'border-ink-200 focus:border-signal' }}"
        >
    @endif

    @if ($hint && ! $hasError)
        <p class="text-small text-ink-500">{{ $hint }}</p>
    @endif

    @error($name)
        <p id="{{ $id }}-error" class="flex items-start gap-1.5 text-small text-failed">
            <span aria-hidden="true" class="leading-[1.35]">✕</span>
            <span>{{ $message }}</span>
        </p>
    @enderror
</div>
