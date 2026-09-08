@props([
    'title' => null,
    'subtitle' => null,
    'flush' => false,
])

{{--
    A panel. Separated from the page by a border and a background step, not by a
    drop shadow -- elevation is reserved for things that genuinely float.
--}}

{{--
    min-w-0 is load-bearing. A grid or flex item defaults to min-width:auto, so
    a card containing a wide element (a table, a heatmap) grows to fit it and
    drags the whole page into a horizontal scroll, instead of letting its own
    overflow-x-auto do the scrolling.
--}}
<section {{ $attributes->merge(['class' => 'min-w-0 rounded-card border border-ink-100 bg-white']) }}>
    @if ($title || isset($actions))
        {{-- Wraps rather than overflowing: at 360px a long title plus an action
             does not fit on one line, and squeezing it produces a horizontal
             scrollbar on the whole page. --}}
        <header class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-b border-ink-100 px-5 py-3.5">
            <div class="min-w-0">
                @if ($title)
                    <h2 class="truncate text-h2">{{ $title }}</h2>
                @endif
                @if ($subtitle)
                    <p class="mt-0.5 truncate text-small text-ink-500">{{ $subtitle }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div class="{{ $flush ? '' : 'p-5' }}">
        {{ $slot }}
    </div>
</section>
