@props([
    'caption' => '',
    'account' => null,
    'media' => null,
    'type' => 'post',
    'when' => null,
])

@php
    use Illuminate\Support\Str;

    // Facebook shows considerably more before "See more" than Instagram does,
    // which is exactly why a caption written for one reads badly on the other.
    $limit = 250;
    $plain = trim($caption);
    $truncated = mb_strlen($plain) > $limit;
    $visible = $truncated ? mb_substr($plain, 0, $limit) : $plain;

    $pageName = $account?->name ?: 'Your Page';
    $isVertical = in_array($type, ['reel', 'story'], true);

    // Per-icon viewBox, for the reason spelled out in the Instagram preview:
    // the thumb fills 19.7 of its 24 units and the share arrow only 17, so a
    // shared box renders them at visibly different sizes.
    $actions = [
        'Like' => ['-0.88 -0.48 25.26 25.26', 'M7 22V9.5l4.6-7.2a1 1 0 0 1 1.8.5V9h5.1a2 2 0 0 1 2 2.4l-1.6 8A2 2 0 0 1 17 21H7zM7 22H4a1 1 0 0 1-1-1v-11a1 1 0 0 1 1-1h3'],
        'Comment' => ['0.46 0.46 23.08 23.08', 'M21 11.5a8.4 8.4 0 0 1-9 8.4 9.5 9.5 0 0 1-3.4-.6L3 21l1.8-4.9A8.2 8.2 0 0 1 3.6 11.5a8.4 8.4 0 0 1 9-8.4 8.4 8.4 0 0 1 8.4 8.4z'],
        'Share' => ['1.1 0.6 21.79 21.79', 'M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7M12 3v13M12 3 7.5 7.5M12 3l4.5 4.5'],
    ];
@endphp

<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-card border border-ink-200 bg-white']) }}>

    {{-- ================================================================= --}}
    {{-- Header                                                            --}}
    {{-- ================================================================= --}}
    <div class="flex items-center gap-2.5 px-3 py-3">
        <span class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-ink-100">
            @if ($account?->avatar_url)
                <img src="{{ $account->avatar_url }}" alt="" class="size-full object-cover">
            @else
                <span class="text-micro font-semibold text-ink-500">
                    {{ Str::upper(Str::substr($pageName, 0, 2)) }}
                </span>
            @endif
        </span>

        <span class="min-w-0 flex-1 leading-tight">
            <span class="block truncate text-small font-semibold text-ink-900">{{ $pageName }}</span>

            {{-- Timestamp and audience sit on one line under the name. The
                 globe is drawn, not typed: an emoji renders differently on
                 every machine the team previews from. --}}
            <span class="mt-0.5 flex items-center gap-1 text-micro text-ink-500">
                <span class="truncate" data-numeric>{{ $when ?: 'Scheduled' }}</span>
                <span aria-hidden="true">·</span>
                <svg viewBox="0 0 24 24" class="size-3 shrink-0" fill="currentColor" aria-hidden="true">
                    <path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 2c.9 0 2.3 1.9 2.8 5H9.2C9.7 5.9 11.1 4 12 4zM7.2 9c.2-1.6.7-3 1.3-4.1A8 8 0 0 0 5 9h2.2zM4.3 11h2.6a20 20 0 0 0 0 2H4.3a8 8 0 0 1 0-2zm.7 4h2.2c.2 1.6.7 3 1.3 4.1A8 8 0 0 1 5 15zm3.9-2a18 18 0 0 1 0-2h6.2a18 18 0 0 1 0 2H8.9zm.3 2h5.6c-.5 3.1-1.9 5-2.8 5s-2.3-1.9-2.8-5zm6.6 4.1c.6-1.1 1-2.5 1.3-4.1H19a8 8 0 0 1-3.2 4.1zM17.1 13a20 20 0 0 0 0-2h2.6a8 8 0 0 1 0 2h-2.6zM19 9h-2.2c-.3-1.6-.7-3-1.3-4.1A8 8 0 0 1 19 9z"/>
                </svg>
            </span>
        </span>

        <svg viewBox="0 0 24 24" class="size-5 shrink-0 text-ink-500" fill="currentColor" aria-hidden="true">
            <circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>
        </svg>
    </div>

    {{-- ================================================================= --}}
    {{-- Caption, above the media as Facebook orders it                    --}}
    {{-- ================================================================= --}}
    @if ($plain !== '')
        <p class="whitespace-pre-line break-words px-3 pb-3 text-small leading-normal text-ink-900" dir="auto">
            {{ $visible }}@if ($truncated)<span class="text-ink-500">… See more</span>@endif
        </p>
    @else
        <p class="px-3 pb-3 text-small italic text-ink-500">Your caption appears here.</p>
    @endif

    {{-- ================================================================= --}}
    {{-- Media                                                             --}}
    {{-- ================================================================= --}}
    <div class="relative bg-ink-050 {{ $isVertical ? 'aspect-[9/16]' : 'aspect-[1.91/1]' }}">
        @if ($media?->thumbnailUrl())
            <img src="{{ $media->thumbnailUrl() }}" alt="" class="size-full object-cover">
        @else
            <div class="flex size-full flex-col items-center justify-center gap-2 text-ink-400"
                 style="background-image: repeating-linear-gradient(45deg, transparent, transparent 7px, rgba(15,23,42,.035) 7px, rgba(15,23,42,.035) 14px);">
                <x-icon name="media" class="size-6 text-ink-300" />
                <span class="rounded-full bg-white px-2 py-0.5 text-micro font-semibold text-ink-500 shadow-sm"
                      data-numeric>{{ $isVertical ? '9:16' : '1.91:1' }} crop</span>
            </div>
        @endif

        @if ($type === 'reel')
            <span class="absolute right-3 top-3 rounded-full bg-ink-900/60 px-2 py-0.5 text-micro font-semibold text-white">
                Reel
            </span>
        @endif
    </div>

    {{-- ================================================================= --}}
    {{-- Actions                                                           --}}
    {{-- ================================================================= --}}
    {{--
        No reaction counts: an unpublished post genuinely has none, and inventing
        "218 likes" to make a mock look busy would misrepresent the post to the
        client reviewing it.
    --}}
    <div class="mt-1 flex items-stretch gap-1 border-t border-ink-100 px-1.5 py-1" aria-hidden="true">
        @foreach ($actions as $label => [$viewBox, $path])
            <span class="flex flex-1 items-center justify-center gap-1.5 rounded-control py-1.5 text-ink-500">
                <svg viewBox="{{ $viewBox }}" class="size-[1.05rem] shrink-0" fill="none" stroke="currentColor"
                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                    <path d="{{ $path }}" vector-effect="non-scaling-stroke"/>
                </svg>
                <span class="text-small font-semibold">{{ $label }}</span>
            </span>
        @endforeach
    </div>
</div>
