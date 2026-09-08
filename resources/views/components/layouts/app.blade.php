@props(['title' => 'Calendar'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title }} · {{ config('app.name') }}</title>

    {{-- Self-hosted DM Sans. Preloaded because it is the only typeface and a
         swap would reflow every number on the calendar. --}}
    <link rel="preload" href="/fonts/dm-sans-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-canvas text-ink-700 antialiased">

{{-- Full-bleed. The shell covers the viewport rather than floating as a card
     inside it: the calendar is the product, and every pixel of grid is one more
     post visible without scrolling. --}}
<div x-data="{ mobileNav: false }" class="min-h-screen">
    <div class="flex min-h-screen bg-white">

        {{-- ============================================================ --}}
        {{-- Sidebar. Full at >=1280px, icon rail 768-1279, hidden below. --}}
        {{-- ============================================================ --}}
        <aside class="hidden shrink-0 flex-col border-r border-ink-100 bg-white
                      md:sticky md:top-0 md:flex md:h-screen md:w-rail xl:w-sidebar">

            <div class="flex h-16 items-center gap-2.5 px-4 xl:px-5">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-control bg-signal text-white">
                    <span class="text-body font-bold leading-none">G</span>
                </span>
                <span class="hidden text-h2 tracking-[-0.01em] xl:block">{{ config('app.name') }}</span>
            </div>

            <nav class="flex-1 space-y-0.5 overflow-y-auto px-2.5 pb-4 xl:px-3" aria-label="Main">
                @php
                    $nav = [
                        ['dashboard', 'dashboard', 'Dashboard', null],
                        ['calendar',  'calendar',  'Calendar',  null],
                        ['posts.index', 'posts',   'Posts',     null],
                        ['approvals', 'approvals', 'Approvals', $pendingApprovals ?? null],
                        ['media.index', 'media',   'Media',     null],
                        ['insights',  'insights',  'Insights',  null],
                        ['import.index', 'import', 'Import',    null],
                        ['templates.index', 'templates', 'Templates', null],
                    ];
                @endphp

                @foreach ($nav as [$route, $icon, $label, $badge])
                    <x-nav-item :href="route($route)" :icon="$icon"
                                :active="request()->routeIs($route)" :badge="$badge"
                                class="justify-center xl:justify-start" title="{{ $label }}">
                        <span class="hidden xl:inline">{{ $label }}</span>
                    </x-nav-item>
                @endforeach

                <div class="mt-4! border-t border-ink-100 pt-3">
                    @can('view-activity-log')
                        <p class="mb-1 hidden px-3 text-micro uppercase text-ink-300 xl:block">Admin</p>

                        <x-nav-item :href="route('activity')" icon="activity"
                                    :active="request()->routeIs('activity')"
                                    class="justify-center xl:justify-start" title="Activity">
                            <span class="hidden xl:inline">Activity</span>
                        </x-nav-item>
                    @endcan

                    {{-- Settings is reachable by both roles: API configuration
                         lives there, and both roles may configure it. The tabs
                         inside filter themselves by capability. --}}
                    <x-nav-item :href="route('settings.meta-app')" icon="settings"
                                :active="request()->routeIs('settings.*')"
                                class="justify-center xl:justify-start" title="Settings">
                        <span class="hidden xl:inline">Settings</span>
                    </x-nav-item>
                </div>
            </nav>

            {{-- Token health lives at the foot of the nav, where the reference
                 UI puts its promo card. A token that is about to expire is the
                 one piece of standing news that stops the product working. --}}
            @can('view-tokens')
                @if (($expiringTokens ?? 0) > 0)
                    <div class="hidden px-3 pb-4 xl:block">
                        <a href="{{ route('settings.accounts') }}"
                           class="block rounded-card border border-pending/25 bg-pending-soft p-3.5 transition-colors hover:border-pending/50">
                            <span class="flex items-center gap-2 text-small font-semibold text-pending">
                                <x-icon name="warning" class="size-4" />
                                {{ $expiringTokens }} token{{ $expiringTokens === 1 ? '' : 's' }} expiring
                            </span>
                            <span class="mt-1 block text-small leading-snug text-ink-500">
                                Reconnect before they lapse or publishing stops.
                            </span>
                        </a>
                    </div>
                @endif
            @endcan
        </aside>

        {{-- ============================================================ --}}
        {{-- Main column                                                  --}}
        {{-- ============================================================ --}}
        <div class="flex min-w-0 flex-1 flex-col bg-ink-050">

            <header class="flex h-16 shrink-0 items-center gap-3 border-b border-ink-100 bg-white px-4 xl:px-6">

                <button type="button" @click="mobileNav = true"
                        class="-ml-1 rounded-control p-2 text-ink-500 hover:bg-ink-050 hover:text-ink-900 md:hidden"
                        aria-label="Open navigation">
                    <x-icon name="menu" class="size-5" />
                </button>

                <label class="relative hidden min-w-0 flex-1 items-center sm:flex md:max-w-sm">
                    <span class="sr-only">Search posts</span>
                    <x-icon name="search" class="pointer-events-none absolute left-3 size-[16px] text-ink-500" />
                    <input type="search" placeholder="Search posts, captions, accounts"
                           class="w-full rounded-control border border-ink-100 bg-ink-050 py-2 pl-9 pr-3 text-small
                                  text-ink-900 placeholder:text-ink-500 focus:border-signal focus:bg-white focus:outline-none">
                </label>

                <div class="ml-auto flex items-center gap-1">
                    <a href="{{ route('approvals') }}"
                       class="relative rounded-control p-2 text-ink-500 hover:bg-ink-050 hover:text-ink-900"
                       aria-label="Approvals">
                        <x-icon name="bell" class="size-[18px]" />
                        @if (($pendingApprovals ?? 0) > 0)
                            <span class="absolute right-1.5 top-1.5 size-1.5 rounded-full bg-pending"></span>
                        @endif
                    </a>

                    {{-- Account menu --}}
                    <div x-data="{ open: false }" class="relative">
                        <button type="button" @click="open = !open" :aria-expanded="open"
                                class="flex items-center gap-2 rounded-control p-1 pr-2 hover:bg-ink-050">
                            <span class="flex size-7 items-center justify-center rounded-full bg-ink-900 text-micro font-semibold text-white">
                                {{ Str::of(auth()->user()->name)->explode(' ')->take(2)->map(fn ($p) => Str::substr($p, 0, 1))->implode('') }}
                            </span>
                            <x-icon name="chevron-down" class="size-3.5 text-ink-500" />
                        </button>

                        <div x-show="open" x-cloak x-transition.opacity.duration.120ms
                             @click.outside="open = false" @keydown.escape.window="open = false"
                             class="absolute right-0 z-40 mt-1.5 w-60 rounded-card border border-ink-100 bg-white p-1.5 shadow-drawer">

                            <div class="px-2.5 py-2">
                                <p class="truncate text-small font-semibold text-ink-900">{{ auth()->user()->name }}</p>
                                <p class="truncate text-small text-ink-500">{{ auth()->user()->email }}</p>
                                <p class="mt-1.5 text-micro text-ink-500">
                                    {{ auth()->user()->role->label() }} ·
                                    <span data-numeric>{{ auth()->user()->displayTimezone() }}</span>
                                </p>
                            </div>

                            <div class="my-1 border-t border-ink-100"></div>

                            <a href="{{ route('profile') }}"
                               class="flex items-center gap-2.5 rounded-control px-2.5 py-2 text-small text-ink-700 hover:bg-ink-050">
                                <x-icon name="user" class="size-4 text-ink-500" /> Your profile
                            </a>

                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit"
                                        class="flex w-full items-center gap-2.5 rounded-control px-2.5 py-2 text-small text-ink-700 hover:bg-ink-050">
                                    <x-icon name="logout" class="size-4 text-ink-500" /> Sign out
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            {{-- Standing alerts. Persistent by design: an expiring token or a
                 stuck post is not a toast you can dismiss and forget. --}}
            @isset($banner)
                <div class="border-b border-ink-100 bg-white px-4 py-2.5 xl:px-6">{{ $banner }}</div>
            @endisset

            <main class="min-w-0 flex-1 px-4 pb-24 pt-5 md:pb-8 xl:px-6">
                @isset($header)
                    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">{{ $header }}</div>
                @endisset

                {{ $slot }}
            </main>
        </div>
    </div>

    {{-- ================================================================ --}}
    {{-- Mobile: bottom tab bar + slide-over nav                          --}}
    {{-- ================================================================ --}}
    <nav class="fixed inset-x-0 bottom-0 z-30 flex border-t border-ink-100 bg-white pb-[env(safe-area-inset-bottom)] md:hidden"
         aria-label="Main">
        @php
            // Three tabs plus "More" is the most that fits at 360px, so
            // Approvals lives in the slide-over -- the dashboard's awaiting
            // -approval tile is the prominent route to it now.
            $tabs = [
                ['dashboard', 'dashboard', 'Home'],
                ['calendar', 'calendar', 'Calendar'],
                ['posts.index', 'posts', 'Posts'],
            ];
        @endphp

        {{-- min-w-0 on every tab is load-bearing: a flex item defaults to
             min-width:auto, so four labels that do not fit at 360px would
             refuse to shrink and stretch this fixed bar past the viewport,
             which drags the whole document into a horizontal scroll. --}}
        @foreach ($tabs as [$route, $icon, $label])
            <a href="{{ route($route) }}"
               class="flex min-w-0 flex-1 flex-col items-center gap-1 px-1 py-2.5 text-micro font-semibold
                      {{ request()->routeIs($route) ? 'text-signal' : 'text-ink-500' }}"
               @if (request()->routeIs($route)) aria-current="page" @endif>
                <x-icon :name="$icon" class="size-5 shrink-0" />
                <span class="w-full truncate text-center">{{ $label }}</span>
            </a>
        @endforeach

        <button type="button" @click="mobileNav = true"
                class="flex min-w-0 flex-1 flex-col items-center gap-1 px-1 py-2.5 text-micro font-semibold text-ink-500">
            <x-icon name="more" class="size-5 shrink-0" />
            <span class="w-full truncate text-center">More</span>
        </button>
    </nav>

    <div x-show="mobileNav" x-cloak class="fixed inset-0 z-50 md:hidden">
        <div x-show="mobileNav" x-transition.opacity @click="mobileNav = false"
             class="absolute inset-0 bg-ink-900/40"></div>

        <div x-show="mobileNav"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
             class="absolute inset-y-0 left-0 flex w-[17rem] flex-col bg-white shadow-drawer">

            <div class="flex h-16 items-center justify-between px-4">
                <span class="text-h2">{{ config('app.name') }}</span>
                <button type="button" @click="mobileNav = false"
                        class="rounded-control p-2 text-ink-500 hover:bg-ink-050" aria-label="Close navigation">
                    <x-icon name="close" class="size-5" />
                </button>
            </div>

            <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 pb-6">
                @foreach ($nav as [$route, $icon, $label, $badge])
                    <x-nav-item :href="route($route)" :icon="$icon"
                                :active="request()->routeIs($route)" :badge="$badge">{{ $label }}</x-nav-item>
                @endforeach

                <div class="mt-4! border-t border-ink-100 pt-3">
                    @can('view-activity-log')
                        <x-nav-item :href="route('activity')" icon="activity"
                                    :active="request()->routeIs('activity')">Activity</x-nav-item>
                    @endcan

                    <x-nav-item :href="route('settings.meta-app')" icon="settings"
                                :active="request()->routeIs('settings.*')">Settings</x-nav-item>
                </div>
            </nav>
        </div>
    </div>
</div>

<x-toasts />

@livewireScripts
</body>
</html>
