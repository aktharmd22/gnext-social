@props([
    'variant' => 'secondary',
    'size' => 'default',
    'href' => null,
    'icon' => null,
])

@php
    $base = 'inline-flex items-center justify-center gap-2 font-semibold rounded-control '
        .'transition-colors disabled:opacity-50 disabled:pointer-events-none whitespace-nowrap';

    $sizes = [
        'sm' => 'text-small px-2.5 py-1.5',
        'default' => 'text-small px-3.5 py-2',
        'lg' => 'text-body px-4 py-2.5',
    ];

    $variants = [
        // The one saturated control on screen. Reserved for the single most
        // likely action per view.
        'primary' => 'bg-signal text-white hover:bg-signal-strong',

        'secondary' => 'bg-white text-ink-700 border border-ink-200 hover:bg-ink-050 hover:text-ink-900',

        'ghost' => 'text-ink-500 hover:bg-ink-050 hover:text-ink-900',

        // Destructive actions announce themselves, but stay quiet until hovered
        // so a list of rows is not a wall of red.
        'danger' => 'bg-white text-failed border border-ink-200 hover:bg-failed-soft hover:border-failed',
    ];

    $classes = implode(' ', [$base, $sizes[$size] ?? $sizes['default'], $variants[$variant] ?? $variants['secondary']]);
    $tag = $href ? 'a' : 'button';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" @else type="{{ $attributes->get('type', 'button') }}" @endif
    {{ $attributes->merge(['class' => $classes])->except('type') }}
>
    @if ($icon)
        <x-icon :name="$icon" class="size-4" />
    @endif
    {{ $slot }}
</{{ $tag }}>
