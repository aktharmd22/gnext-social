@props([
    'href',
    'icon',
    'active' => false,
    'badge' => null,
])

{{--
    Sidebar navigation row.

    Active state carries the accent, and it is the only place in the chrome that
    does besides the primary button and the focus ring.
--}}

<a href="{{ $href }}"
   @if ($active) aria-current="page" @endif
   {{ $attributes->merge([
       'class' => 'group relative flex items-center gap-3 rounded-control px-3 py-2 text-small font-medium transition-colors '
           .($active
               ? 'bg-signal-weak text-signal font-semibold'
               : 'text-ink-500 hover:bg-ink-050 hover:text-ink-900'),
   ]) }}>

    @if ($active)
        <span aria-hidden="true"
              class="absolute left-0 top-1/2 h-4 w-[3px] -translate-y-1/2 rounded-r-full bg-signal"></span>
    @endif

    <x-icon :name="$icon" class="size-[18px] shrink-0" />

    <span class="flex-1 truncate">{{ $slot }}</span>

    @if ($badge)
        <span data-numeric
              class="ml-auto inline-flex min-w-5 items-center justify-center rounded-full px-1.5 py-0.5 text-micro font-semibold
                     {{ $active ? 'bg-signal text-white' : 'bg-ink-100 text-ink-500 group-hover:bg-ink-200' }}">
            {{ $badge }}
        </span>
    @endif
</a>
