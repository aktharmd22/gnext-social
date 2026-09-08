@props([
    'status',
    'size' => 'default',
])

{{--
    Status, never carried by colour alone.

    Every pill renders a glyph AND a text label alongside its hue, so the
    calendar stays readable in greyscale, in print, and to colour-blind users.
    That pairing is a requirement, not a nicety -- do not add a colour-only
    variant of this component.
--}}

@php
    $token = $status->token();

    $sizing = $size === 'sm'
        ? 'text-micro px-1.5 py-0.5 gap-1'
        : 'text-small px-2 py-[3px] gap-1.5';
@endphp

<span {{ $attributes->merge([
        'class' => "inline-flex items-center rounded-full font-semibold whitespace-nowrap $sizing",
    ]) }}
    style="background-color: var(--color-{{ $token }}-soft); color: var(--color-{{ $token }});"
    @class(['is-publishing' => $status->value === 'publishing'])
>
    <span aria-hidden="true" class="leading-none">{{ $status->glyph() }}</span>
    <span>{{ $status->label() }}</span>
</span>
