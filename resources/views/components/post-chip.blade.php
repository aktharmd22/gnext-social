@props([
    'post',
    'tz',
    'draggable' => false,
    'href' => null,
])

{{--
    One post on one day.

    Carries four things and no more: when, what it is, where it goes, and how it
    is doing. Status is the only saturated colour on it, and it is always a
    colour AND a glyph -- the left spine reads as a hue, the glyph reads without
    one.

    An anchor, not a button: the composer is a page, so a chip is a link to it.
    That gives Enter, Ctrl-click for a new tab, and middle-click for free -- and
    it is the keyboard route to rescheduling, since drag-and-drop has none.
--}}

@php
    $token = $post->status->token();
    $localTime = $post->scheduled_at?->copy()->setTimezone($tz);
    $canMove = $post->status->isReschedulable();

    $platforms = $post->targets
        ->map(fn ($target) => $target->socialAccount?->platform)
        ->filter()
        ->unique(fn ($platform) => $platform->value);
@endphp

<a @if ($href) href="{{ $href }}" @endif
    @if ($draggable && $canMove)
        draggable="true"
        x-on:dragstart="event.dataTransfer.setData('text/plain', '{{ $post->id }}'); event.dataTransfer.effectAllowed = 'move'; $el.classList.add('opacity-40')"
        x-on:dragend="$el.classList.remove('opacity-40')"
    @endif
    {{ $attributes->merge([
        'class' => 'group relative flex w-full flex-col gap-1 overflow-hidden rounded-chip border border-ink-100 '
            .'bg-white py-1.5 pl-2.5 pr-2 text-left transition-colors hover:border-ink-200 hover:bg-ink-050 '
            .($draggable && $canMove ? 'cursor-grab active:cursor-grabbing' : ''),
    ]) }}
    title="{{ $post->title }} — {{ $post->status->label() }}{{ $localTime ? ', '.$localTime->format('D j M, H:i') : '' }}{{ $canMove ? '' : ' (published posts cannot be moved)' }}">

    <span aria-hidden="true" class="absolute inset-y-0 left-0 w-0.75"
          style="background-color: var(--color-{{ $token }});"></span>

    <span class="flex items-center gap-1.5">
        @if ($localTime)
            <time datetime="{{ $post->scheduled_at->toIso8601String() }}"
                  class="text-micro font-semibold text-ink-500">{{ $localTime->format('H:i') }}</time>
        @endif

        <span aria-hidden="true"
              class="text-micro leading-none @if($post->status->value === 'publishing') is-publishing @endif"
              style="color: var(--color-{{ $token }});">{{ $post->status->glyph() }}</span>

        <span class="sr-only">{{ $post->status->label() }}</span>

        <span class="ml-auto flex items-center gap-0.5">
            @foreach ($platforms as $platform)
                <x-platform-glyph :platform="$platform" class="size-3" />
            @endforeach
        </span>
    </span>

    <span class="truncate text-small font-medium leading-tight text-ink-900" dir="auto">
        {{ $post->title ?: Str::limit(strip_tags((string) $post->caption), 40) }}
    </span>
</a>
