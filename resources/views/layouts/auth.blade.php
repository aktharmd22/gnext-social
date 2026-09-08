<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Sign in') · {{ config('app.name') }}</title>

    <link rel="preload" href="/fonts/dm-sans-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas text-ink-700 antialiased">

<div class="flex min-h-screen items-center justify-center p-4">
    <div class="w-full max-w-[26rem]">

        <div class="mb-6 flex items-center gap-2.5">
            <span class="flex size-9 items-center justify-center rounded-control bg-signal text-white">
                <span class="text-h2 font-bold leading-none">G</span>
            </span>
            <span class="text-h1">{{ config('app.name') }}</span>
        </div>

        <div class="rounded-card border border-ink-100 bg-white p-6 sm:p-7">
            <h1 class="text-h1">@yield('heading', 'Sign in')</h1>

            @hasSection('subheading')
                <p class="mt-1.5 text-small text-ink-500">@yield('subheading')</p>
            @endif

            <div class="mt-6">
                @yield('form')
            </div>
        </div>

        @hasSection('footer')
            <p class="mt-5 text-center text-small text-ink-500">@yield('footer')</p>
        @endif
    </div>
</div>

</body>
</html>
