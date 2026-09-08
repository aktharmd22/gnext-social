@props([
    'platform',
    'class' => 'size-[15px]',
])

{{--
    Platform marks. Used on glyphs only, never as a surface colour -- the only
    saturated colour on a calendar chip belongs to status.
--}}

@php
    $isFacebook = $platform->value === 'facebook';
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center']) }}
      title="{{ $platform->label() }}">
    <svg class="{{ $class }}" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
        @if ($isFacebook)
            <path d="M13.5 21v-8h2.7l.4-3.1h-3.1V7.9c0-.9.25-1.5 1.55-1.5H16.7V3.6A21 21 0 0 0 14.3 3.5c-2.4 0-4 1.45-4 4.1v2.3H7.6V13h2.7v8z"
                  fill="var(--color-facebook)"/>
        @else
            <rect x="3.2" y="3.2" width="17.6" height="17.6" rx="5"
                  stroke="var(--color-instagram)" stroke-width="1.7"/>
            <circle cx="12" cy="12" r="3.9" stroke="var(--color-instagram)" stroke-width="1.7"/>
            <circle cx="16.9" cy="7.1" r="1.15" fill="var(--color-instagram)"/>
        @endif
    </svg>
    <span class="sr-only">{{ $platform->label() }}</span>
</span>
