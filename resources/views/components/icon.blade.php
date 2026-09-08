@props(['name', 'class' => 'size-[18px]'])

{{--
    Inline SVG icon set. Hand-rolled rather than pulled from an icon library so
    that nothing is fetched at runtime and the stroke weight stays consistent
    with DM Sans at small sizes.
--}}

@php
    $paths = [
        // Four panels: the shape of the dashboard itself.
        'dashboard' => '<rect x="3.5" y="3.5" width="7" height="7" rx="2"/><rect x="13.5" y="3.5" width="7" height="7" rx="2"/><rect x="3.5" y="13.5" width="7" height="7" rx="2"/><rect x="13.5" y="13.5" width="7" height="7" rx="2"/>',
        'calendar' => '<rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/>',
        'posts' => '<rect x="3.5" y="3.5" width="17" height="17" rx="2.5"/><path d="M7.5 9h9M7.5 13h9M7.5 17h5"/>',
        'approvals' => '<path d="M9 12.5l2.2 2.2L15.5 10"/><rect x="3.5" y="3.5" width="17" height="17" rx="2.5"/>',
        'media' => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><circle cx="8.75" cy="9.75" r="1.6"/><path d="M4 16.5l4.5-4 4 3.5 3-2.5 4.5 4"/>',
        'insights' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'import' => '<path d="M12 3.5v11M8 11l4 3.5 4-3.5"/><path d="M4 16v2.5A2 2 0 0 0 6 20.5h12a2 2 0 0 0 2-2V16"/>',
        'templates' => '<rect x="3.5" y="3.5" width="17" height="17" rx="2.5"/><path d="M3.5 9h17M9 9v11.5"/>',
        'activity' => '<path d="M3 12.5h4l2.5-6 4 12 2.5-6H21"/>',
        'settings' => '<circle cx="12" cy="12" r="3.2"/><path d="M19.4 15a1.6 1.6 0 0 0 .32 1.77l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.6 1.6 0 0 0-1.77-.32 1.6 1.6 0 0 0-1 1.47V21a2 2 0 1 1-4 0v-.11a1.6 1.6 0 0 0-1.05-1.47 1.6 1.6 0 0 0-1.77.32l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.6 1.6 0 0 0 .32-1.77 1.6 1.6 0 0 0-1.47-1H3a2 2 0 1 1 0-4h.11A1.6 1.6 0 0 0 4.6 9a1.6 1.6 0 0 0-.33-1.77l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.6 1.6 0 0 0 8.87 4.7 1.6 1.6 0 0 0 9.87 3.23V3a2 2 0 1 1 4 0v.11a1.6 1.6 0 0 0 1 1.47 1.6 1.6 0 0 0 1.77-.32l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.6 1.6 0 0 0 19.4 9v.05a1.6 1.6 0 0 0 1.47 1H21a2 2 0 1 1 0 4h-.11a1.6 1.6 0 0 0-1.47 1z"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.4 2.3c-.6.3-1 .9-1 1.6v.3"/><path d="M12 17.2h.01"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'bell' => '<path d="M18 8.5a6 6 0 1 0-12 0c0 6-2 7.5-2 7.5h16s-2-1.5-2-7.5"/><path d="M13.7 19.5a2 2 0 0 1-3.4 0"/>',
        'plus' => '<path d="M12 5.5v13M5.5 12h13"/>',
        'chevron-left' => '<path d="M14.5 5.5L8 12l6.5 6.5"/>',
        'chevron-right' => '<path d="M9.5 5.5L16 12l-6.5 6.5"/>',
        'chevron-down' => '<path d="M5.5 9L12 15.5 18.5 9"/>',
        'logout' => '<path d="M9.5 20.5H6a2 2 0 0 1-2-2v-13a2 2 0 0 1 2-2h3.5"/><path d="M16 16.5l4.5-4.5L16 7.5M20 12H9.5"/>',
        'user' => '<circle cx="12" cy="8" r="3.75"/><path d="M4.5 20.5a7.5 7.5 0 0 1 15 0"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close' => '<path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/>',
        'warning' => '<path d="M12 3.8L2.6 20h18.8L12 3.8z"/><path d="M12 10v4M12 17h.01"/>',
        'more' => '<circle cx="5.5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="18.5" cy="12" r="1.4"/>',
    ];
@endphp

<svg {{ $attributes->merge(['class' => $class]) }}
     viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true" focusable="false">
    {!! $paths[$name] ?? '' !!}
</svg>
