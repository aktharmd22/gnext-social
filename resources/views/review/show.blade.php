@php
    use App\Enums\Platform;

    $when = $post->scheduled_at?->copy()->setTimezone($timezone);

    $facebook = $post->targets->first(fn ($t) => $t->socialAccount?->platform === Platform::Facebook)?->socialAccount;
    $instagram = $post->targets->first(fn ($t) => $t->socialAccount?->platform === Platform::Instagram)?->socialAccount;
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- A shared link should never end up in a search index. --}}
    <meta name="robots" content="noindex, nofollow">

    <title>Review — {{ $post->workspace?->name ?? config('app.name') }}</title>

    <link rel="preload" href="/fonts/dm-sans-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas text-ink-700 antialiased">

<div class="mx-auto max-w-2xl px-4 py-8 sm:py-12">

    <header class="mb-6">
        <p class="text-micro font-semibold uppercase text-ink-500">
            {{ $post->workspace?->name ?? config('app.name') }}
        </p>
        <h1 class="mt-1 text-h1">Ready for your review</h1>
        <p class="mt-1.5 text-small text-ink-500">
            @if ($when)
                Planned for <time data-numeric>{{ $when->format('l j F, H:i') }}</time> {{ \App\Support\Zone::label($timezone) }}.
            @endif
            Nothing here is live yet.
        </p>
    </header>

    @if (session('status'))
        <div class="mb-5 rounded-card border border-published/25 bg-published-soft px-4 py-3 text-small text-published">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-5 rounded-card border border-failed/25 bg-failed-soft px-4 py-3 text-small text-failed">
            {{ session('error') }}
        </div>
    @endif

    {{-- ================================================================= --}}
    {{-- Previews                                                          --}}
    {{-- ================================================================= --}}
    <div class="space-y-5">
        @if ($instagram || ! $facebook)
            <div>
                <p class="mb-1.5 flex items-center gap-1.5 text-small font-semibold text-ink-700">
                    <x-platform-glyph :platform="\App\Enums\Platform::Instagram" class="size-3.5" /> On Instagram
                </p>
                <x-preview.instagram
                    :caption="$post->caption"
                    :account="$instagram"
                    :media="$post->media->first()"
                    :type="$post->type->value"
                    :first-comment="$post->first_comment"
                    :when="$when?->format('j M, H:i')" />
            </div>
        @endif

        @if ($facebook)
            <div>
                <p class="mb-1.5 flex items-center gap-1.5 text-small font-semibold text-ink-700">
                    <x-platform-glyph :platform="\App\Enums\Platform::Facebook" class="size-3.5" /> On Facebook
                </p>
                <x-preview.facebook
                    :caption="$post->caption"
                    :account="$facebook"
                    :media="$post->media->first()"
                    :type="$post->type->value"
                    :when="$when?->format('j M, H:i')" />
            </div>
        @endif

        @if (filled($post->caption_ar))
            <div class="rounded-card border border-ink-200 bg-white p-4">
                <p class="mb-2 text-micro font-semibold uppercase text-ink-500">Arabic caption</p>
                <p class="whitespace-pre-line break-words text-body leading-relaxed text-ink-900" dir="rtl" lang="ar">{{ $post->caption_ar }}</p>
            </div>
        @endif
    </div>

    {{-- ================================================================= --}}
    {{-- Decision                                                          --}}
    {{-- ================================================================= --}}
    <div class="mt-6 rounded-card border border-ink-100 bg-white p-5">
        @if ($alreadyActed)
            <p class="text-small text-ink-500">
                You have already responded to this one. You can add another comment below if something else comes to mind.
            </p>
        @endif

        <form method="POST" action="{{ route('review.decide', ['uuid' => $post->public_uuid, 'signature' => request('signature'), 'expires' => request('expires')]) }}"
              class="space-y-4"
              x-data="{ mode: 'approve' }">
            @csrf

            <div class="flex flex-wrap gap-2">
                <label class="flex-1">
                    <input type="radio" name="action" value="approve" x-model="mode" class="peer sr-only" checked>
                    <span class="block cursor-pointer rounded-control border px-3 py-2.5 text-center text-small font-semibold
                                 peer-checked:border-published peer-checked:bg-published-soft peer-checked:text-published
                                 border-ink-200 text-ink-700 hover:bg-ink-050">
                        Looks good, approve
                    </span>
                </label>

                <label class="flex-1">
                    <input type="radio" name="action" value="comment" x-model="mode" class="peer sr-only">
                    <span class="block cursor-pointer rounded-control border px-3 py-2.5 text-center text-small font-semibold
                                 peer-checked:border-pending peer-checked:bg-pending-soft peer-checked:text-pending
                                 border-ink-200 text-ink-700 hover:bg-ink-050">
                        Leave a comment
                    </span>
                </label>
            </div>

            <div>
                <label for="note" class="block text-small font-semibold text-ink-900">
                    <span x-show="mode === 'approve'">Anything to add?</span>
                    <span x-show="mode === 'comment'" x-cloak>What should change?</span>
                    <span class="ml-1 font-normal text-ink-500" x-show="mode === 'approve'">optional</span>
                </label>

                <textarea id="note" name="note" rows="3" dir="auto"
                          class="mt-1.5 w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-body
                                 text-ink-900 focus:border-signal focus:outline-none">{{ old('note') }}</textarea>

                @error('note')
                    <p class="mt-1.5 text-small text-failed">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit"
                    class="w-full rounded-control bg-signal px-4 py-2.5 text-body font-semibold text-white hover:bg-signal-strong">
                Send to the team
            </button>
        </form>
    </div>

    <p class="mt-5 text-center text-small text-ink-500">
        This link is read-only and expires. Your response goes to the team, who publish it.
    </p>
</div>

</body>
</html>
