@props([
    'title',
    'body' => null,
    'icon' => 'calendar',
])

{{--
    Empty states are invitations, not apologies. Each one names the next action
    rather than reporting an absence.
--}}

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center text-center px-6 py-14']) }}>
    <div class="flex size-11 items-center justify-center rounded-card bg-ink-050 text-ink-500 border border-ink-100">
        <x-icon :name="$icon" class="size-5" />
    </div>

    <p class="mt-4 text-h2 text-ink-900">{{ $title }}</p>

    @if ($body)
        <p class="mt-1.5 max-w-[42ch] text-small text-ink-500">{{ $body }}</p>
    @endif

    @if (isset($actions))
        <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
            {{ $actions }}
        </div>
    @endif
</div>
