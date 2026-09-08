@props([
    'caption' => '',
    'account' => null,
    'media' => null,
    'type' => 'post',
    'firstComment' => null,
    'when' => null,
])

@php
    use Illuminate\Support\Str;

    /*
     * Instagram truncates a feed caption at roughly 125 characters and hides the
     * rest behind "more". Showing the real truncation point is the whole value
     * of a preview: it is where a call to action silently disappears.
     */
    $limit = 125;
    $plain = trim($caption);
    $truncated = mb_strlen($plain) > $limit;
    $visible = $truncated ? mb_substr($plain, 0, $limit) : $plain;

    $handle = $account?->username ?: ($account?->name ?: 'your_account');
    $isVertical = in_array($type, ['reel', 'story'], true);
    $isCarousel = $type === 'carousel';
@endphp

<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-card border border-ink-200 bg-white']) }}>

    {{-- ================================================================= --}}
    {{-- Header                                                            --}}
    {{-- ================================================================= --}}
    <div class="flex items-center gap-2.5 px-3 py-2.5">
        {{-- The gradient ring is the single most recognisable thing about an
             Instagram avatar; without it the mock reads as generic. --}}
        <span class="flex size-8 shrink-0 items-center justify-center rounded-full p-[2px]"
              style="background: conic-gradient(from 180deg, #F58529, #DD2A7B, #8134AF, #515BD4, #F58529);">
            <span class="flex size-full items-center justify-center overflow-hidden rounded-full bg-white">
                @if ($account?->avatar_url)
                    <img src="{{ $account->avatar_url }}" alt="" class="size-full rounded-full object-cover">
                @else
                    <span class="flex size-full items-center justify-center rounded-full bg-ink-100 text-[9px] font-semibold text-ink-500">
                        {{ Str::upper(Str::substr($handle, 0, 2)) }}
                    </span>
                @endif
            </span>
        </span>

        <span class="min-w-0 flex-1 leading-tight">
            <span class="block truncate text-small font-semibold text-ink-900">{{ $handle }}</span>
            @if ($when)
                <span class="block truncate text-micro text-ink-500" data-numeric>{{ $when }}</span>
            @endif
        </span>

        <svg viewBox="0 0 24 24" class="size-5 shrink-0 text-ink-900" fill="currentColor" aria-hidden="true">
            <circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>
        </svg>
    </div>

    {{-- ================================================================= --}}
    {{-- Media                                                             --}}
    {{-- ================================================================= --}}
    <div class="relative bg-ink-050 {{ $isVertical ? 'aspect-[9/16]' : 'aspect-square' }}">
        @if ($media?->thumbnailUrl())
            <img src="{{ $media->thumbnailUrl() }}" alt="" class="size-full object-cover">
        @else
            {{-- Hatched, not a flat slab: an empty frame should look empty
                 on purpose rather than look like an image that failed. --}}
            <div class="flex size-full flex-col items-center justify-center gap-2 text-ink-400"
                 style="background-image: repeating-linear-gradient(45deg, transparent, transparent 7px, rgba(15,23,42,.035) 7px, rgba(15,23,42,.035) 14px);">
                <x-icon name="media" class="size-7 text-ink-300" />
                <span class="text-small font-semibold text-ink-500">No media yet</span>
                <span class="rounded-full bg-white px-2 py-0.5 text-micro font-semibold text-ink-500 shadow-sm"
                      data-numeric>{{ $isVertical ? '9:16' : '1:1' }} crop</span>
            </div>
        @endif

        @if ($isCarousel)
            {{-- Carousel dots, so a multi-image post does not preview as a
                 single one. --}}
            <div class="absolute inset-x-0 bottom-3 flex items-center justify-center gap-1">
                @foreach (range(1, 3) as $dot)
                    <span class="size-1.5 rounded-full {{ $loop->first ? 'bg-white' : 'bg-white/50' }}"></span>
                @endforeach
            </div>

            <span class="absolute right-3 top-3 rounded-full bg-ink-900/60 px-2 py-0.5 text-micro font-semibold text-white">
                1/3
            </span>
        @endif

        @if ($type === 'reel')
            <span class="absolute right-3 top-3">
                <svg viewBox="0 0 24 24" class="size-5 text-white drop-shadow" fill="currentColor" aria-hidden="true">
                    <path d="M4 4h16a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1zm5.5 4.2v7.6l6-3.8-6-3.8z" opacity=".9"/>
                </svg>
            </span>
        @endif
    </div>

    {{-- ================================================================= --}}
    {{-- Actions                                                           --}}
    {{-- ================================================================= --}}
    {{--
        Each glyph gets its own viewBox rather than a shared "0 0 24 24".

        The paths fill their box by very different amounts -- the heart spans 15
        units, the paper plane 19.5 -- so at an identical `size-6` the plane
        renders visibly larger than the heart. The viewBox is tuned per icon so
        every glyph lands on the same 19.5px optical size; `non-scaling-stroke`
        then keeps all four strokes at 1.7px despite the different scales.
        Normalising these back to a common viewBox is what made them mismatched.
    --}}
    <div class="flex items-center gap-4 px-3 pt-2.5">
        @php
            $igActions = [
                'like' => ['2.77 4.47 18.46 18.46', 'M12 20.5s-7.5-4.6-7.5-9.6a4.3 4.3 0 0 1 7.5-2.9 4.3 4.3 0 0 1 7.5 2.9c0 5-7.5 9.6-7.5 9.6z'],
                'comment' => ['0.92 0.92 22.15 22.15', 'M21 11.5a8.4 8.4 0 0 1-9 8.4 9.5 9.5 0 0 1-3.4-.6L3 21l1.8-4.9A8.2 8.2 0 0 1 3.6 11.5a8.4 8.4 0 0 1 9-8.4 8.4 8.4 0 0 1 8.4 8.4z'],
                'share' => ['0 0 24 24', 'M21.5 2.5 10.5 13.5M21.5 2.5l-7 19-4-8.5-8.5-4 19.5-6.5z'],
                'save' => ['0.92 0.92 22.15 22.15', 'M18 21 12 16.8 6 21V4.5a1.5 1.5 0 0 1 1.5-1.5h9A1.5 1.5 0 0 1 18 4.5V21z'],
            ];
        @endphp

        @foreach ($igActions as $name => [$viewBox, $path])
            <svg viewBox="{{ $viewBox }}"
                 class="size-6 text-ink-900 {{ $name === 'save' ? 'ml-auto' : '' }}"
                 fill="none" stroke="currentColor" stroke-width="1.7"
                 stroke-linecap="round" stroke-linejoin="round"
                 vector-effect="non-scaling-stroke" aria-hidden="true">
                <path d="{{ $path }}" vector-effect="non-scaling-stroke"/>
            </svg>
        @endforeach
    </div>

    {{-- ================================================================= --}}
    {{-- Caption                                                           --}}
    {{-- ================================================================= --}}
    <div class="space-y-1 px-3 pb-3 pt-2">
        @if ($plain !== '')
            <p class="whitespace-pre-line break-words text-small leading-snug text-ink-900" dir="auto">
                <span class="font-semibold">{{ $handle }}</span>
                {{ $visible }}@if ($truncated)<span class="text-ink-500">… more</span>@endif
            </p>
        @else
            <p class="text-small italic text-ink-500">Your caption appears here.</p>
        @endif

        @if (filled($firstComment))
            <p class="whitespace-pre-line break-words pt-0.5 text-small leading-snug text-ink-700" dir="auto">
                <span class="font-semibold text-ink-900">{{ $handle }}</span>
                {{ $firstComment }}
            </p>
        @endif
    </div>
</div>
