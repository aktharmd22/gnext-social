@props(['code', 'title', 'body' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('app.name') }}</title>

    <link rel="preload" href="/fonts/dm-sans-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-canvas text-ink-700 antialiased">

<div class="flex min-h-screen items-center justify-center p-4">
    <div class="w-full max-w-[30rem]">

        <div class="mb-6 flex items-center gap-2.5">
            <span class="flex size-9 items-center justify-center rounded-control bg-signal text-white">
                <span class="text-h2 font-bold leading-none">G</span>
            </span>
            <span class="text-h1">{{ config('app.name') }}</span>
        </div>

        <div class="rounded-card border border-ink-100 bg-white p-6 sm:p-7">
            <p class="text-micro font-semibold uppercase text-ink-500" data-numeric>Error {{ $code }}</p>

            <h1 class="mt-1 text-h1">{{ $title }}</h1>

            @if ($body)
                <p class="mt-2 text-body leading-snug text-ink-700">{{ $body }}</p>
            @endif

            @if (isset($extra))
                <div class="mt-4">{{ $extra }}</div>
            @endif

            <div class="mt-6 flex flex-wrap gap-2">
                {{ $actions ?? '' }}
            </div>
        </div>
    </div>
</div>

</body>
</html>
