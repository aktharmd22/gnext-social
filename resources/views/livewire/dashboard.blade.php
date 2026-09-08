@php
    use App\Enums\Platform;
@endphp

{{--
    The landing screen.

    Structure is deliberate: four numbers, then the two things that need a
    decision (what is going out, what is broken), then the context that explains
    them. Nothing here is decoration -- every panel answers a question someone
    actually asks on a Monday morning.
--}}
<div class="space-y-5">

    {{-- =================================================================== --}}
    {{-- Greeting                                                            --}}
    {{-- =================================================================== --}}
    <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
        <div class="min-w-0">
            <h1 class="text-display">{{ $greeting }}, {{ $firstName }}</h1>
            <p class="mt-1 text-small text-ink-500">
                <time data-numeric>{{ $today->format('l j F') }}</time>
                ·
                <time data-numeric>{{ $today->format('H:i') }}</time>
                {{ \App\Support\Zone::label($tz) }}
            </p>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <x-button :href="route('import.index')" wire:navigate icon="import">Import</x-button>
            <x-button :href="route('posts.create')" wire:navigate variant="primary" icon="plus">New post</x-button>
        </div>
    </div>

    {{-- =================================================================== --}}
    {{-- Headline numbers                                                    --}}
    {{-- =================================================================== --}}
    {{-- Each tile is a link. A number you cannot act on is a wall decoration. --}}
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($tiles as $tile)
            <a href="{{ $tile['href'] }}" wire:navigate
               class="group min-w-0 rounded-card border border-ink-100 bg-white p-4 transition-all
                      hover:border-ink-200 hover:shadow-float">
                <div class="flex items-start justify-between gap-3">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-control"
                          style="background-color: var(--color-{{ $tile['token'] }}-soft); color: var(--color-{{ $tile['token'] }});">
                        <x-icon :name="$tile['icon']" class="size-[18px]" />
                    </span>

                    <x-icon name="chevron-right"
                            class="size-4 shrink-0 text-ink-300 transition-colors group-hover:text-ink-500" />
                </div>

                <div class="mt-3 flex items-end gap-2">
                    <p class="text-display leading-none text-ink-900" data-numeric>{{ number_format($tile['value']) }}</p>

                    @if (($tile['delta'] ?? 0) !== 0)
                        {{-- Direction is an arrow as well as a colour, so the
                             chip survives greyscale and colour blindness. --}}
                        <span class="mb-0.5 flex items-center gap-0.5 text-micro font-semibold"
                              style="color: var(--color-{{ $tile['delta'] > 0 ? 'rise' : 'fall' }});"
                              title="{{ $tile['deltaNote'] }}">
                            <span aria-hidden="true">{{ $tile['delta'] > 0 ? '↑' : '↓' }}</span>
                            <span data-numeric>{{ abs($tile['delta']) }}</span>
                        </span>
                    @endif
                </div>

                <p class="mt-2 text-small font-semibold text-ink-900">{{ $tile['label'] }}</p>
                <p class="mt-0.5 truncate text-micro font-normal text-ink-500">{{ $tile['note'] }}</p>
            </a>
        @endforeach
    </div>

    {{-- =================================================================== --}}
    {{-- Body                                                                --}}
    {{-- =================================================================== --}}
    <div class="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">

        {{-- ---------------------------------------------------- left column --}}
        <div class="min-w-0 space-y-4">

            {{-- Publishing activity ---------------------------------------- --}}
            <x-card title="Publishing activity"
                    subtitle="{{ $chart['total'] }} post{{ $chart['total'] === 1 ? '' : 's' }} published in the last {{ $chartDays }} days">
                <x-slot:actions>
                    {{-- A segmented control rather than a select: three options
                         is not a menu. --}}
                    <div class="flex rounded-control border border-ink-200 p-0.5">
                        @foreach ([7, 14, 30] as $days)
                            <button type="button" wire:click="setChartDays({{ $days }})"
                                    @class([
                                        'rounded-[0.4rem] px-2.5 py-1 text-micro font-semibold transition-colors',
                                        'bg-signal text-white' => $chartDays === $days,
                                        'text-ink-500 hover:text-ink-900' => $chartDays !== $days,
                                    ])>{{ $days }}d</button>
                        @endforeach
                    </div>
                </x-slot:actions>

                @if ($chart['total'] === 0)
                    <div class="rounded-control border border-dashed border-ink-200 px-4 py-10 text-center">
                        <p class="text-small font-semibold text-ink-700">Nothing published yet in this window</p>
                        <p class="mt-1 text-small text-ink-500">
                            Bars appear here once posts start going out.
                        </p>
                    </div>
                @else
                    {{--
                        A bar chart, because the question is magnitude per day.
                        One series, so no legend -- the heading names it.

                        Gridlines and a labelled axis rather than bars floating
                        in white space: without a scale, four bars of height one
                        look identical to four bars of height forty.
                    --}}
                    @php $mid = (int) round($chart['scale'] / 2); @endphp

                    <div class="flex gap-3">
                        <div class="flex h-40 w-5 shrink-0 flex-col justify-between text-right text-micro font-normal text-ink-300">
                            <span data-numeric>{{ $chart['scale'] }}</span>
                            <span data-numeric>{{ $mid }}</span>
                            <span data-numeric>0</span>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="relative h-40">
                                <div class="absolute inset-0 flex flex-col justify-between" aria-hidden="true">
                                    <span class="h-px bg-ink-100"></span>
                                    <span class="h-px bg-ink-100"></span>
                                    <span class="h-px bg-ink-200"></span>
                                </div>

                                <div class="relative flex h-full items-end gap-1.5" role="img"
                                     aria-label="Posts published per day over the last {{ $chartDays }} days. Peak {{ $chart['peak'] }}.">
                                    @foreach ($chart['days'] as $day)
                                        @php $height = $day['count'] / $chart['scale'] * 100; @endphp

                                        <div class="group/bar relative flex h-full min-w-0 flex-1 items-end justify-center rounded-t-chip transition-colors hover:bg-ink-050">
                                            {{-- The chart is HTML, so it is
                                                 interactive by default. --}}
                                            <span class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden
                                                         -translate-x-1/2 whitespace-nowrap rounded-control bg-ink-900 px-2
                                                         py-1 text-micro font-semibold text-white shadow-float
                                                         group-hover/bar:block">
                                                {{ $day['count'] }} on {{ $day['label'] }}
                                            </span>

                                            <span class="w-full max-w-10 rounded-t-[4px] transition-colors"
                                                  style="height: {{ $day['count'] > 0 ? max($height, 3) : 0 }}%;
                                                         background-color: var(--color-chart);"></span>

                                            {{-- Zero days still get a mark, so a
                                                 gap reads as a gap rather than
                                                 as missing data. --}}
                                            @if ($day['count'] === 0)
                                                <span class="absolute bottom-0 h-[3px] w-full max-w-10 rounded-full bg-ink-200"></span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Axis. Recessive, and thinned out so 30 labels do
                                 not collide at 360px. --}}
                            <div class="mt-2 flex gap-1.5">
                                @foreach ($chart['days'] as $index => $day)
                                    <span class="min-w-0 flex-1 truncate text-center text-micro font-normal text-ink-500">
                                        @if ($loop->first || $loop->last || ($chartDays <= 14 && $index % 2 === 0))
                                            {{ $day['weekday'] }}
                                        @endif
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    </div>

                @endif
            </x-card>

            {{-- Next up ---------------------------------------------------- --}}
            <x-card title="Next up" subtitle="The queue in order, in {{ \App\Support\Zone::label($tz) }}" flush>
                <x-slot:actions>
                    <a href="{{ route('calendar') }}" wire:navigate
                       class="text-small font-semibold text-signal hover:underline">Open calendar</a>
                </x-slot:actions>

                @forelse ($upcoming as $post)
                    @php
                        $when = $post->scheduled_at->copy()->setTimezone($tz);
                        // Not $platforms: that name belongs to the split card
                        // below, and Blade @php blocks share one scope.
                        $postPlatforms = $post->targets
                            ->map(fn ($t) => $t->socialAccount?->platform)
                            ->filter()
                            ->unique(fn (Platform $p) => $p->value);
                    @endphp

                    <a href="{{ route('posts.edit', $post) }}" wire:navigate
                       class="flex items-center gap-3 border-b border-ink-100 px-5 py-3 last:border-0 hover:bg-ink-050">

                        {{-- The date block reads as a calendar tear-off, which
                             is how the queue is scanned: by day first. --}}
                        <span class="flex size-11 shrink-0 flex-col items-center justify-center rounded-control border border-ink-100 bg-ink-050">
                            <span class="text-micro uppercase leading-none text-ink-500">{{ $when->format('M') }}</span>
                            <span class="text-body font-bold leading-tight text-ink-900" data-numeric>{{ $when->format('j') }}</span>
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-small font-semibold text-ink-900">
                                {{ $post->title ?: 'Untitled post' }}
                            </span>
                            <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-micro font-normal text-ink-500">
                                <time data-numeric>{{ $when->format('D, H:i') }}</time>
                                <span aria-hidden="true">·</span>
                                <span>{{ $post->type->label() }}</span>

                                @if ($postPlatforms->isNotEmpty())
                                    <span aria-hidden="true">·</span>
                                    <span class="flex items-center gap-1">
                                        @foreach ($postPlatforms as $platform)
                                            <x-platform-glyph :platform="$platform" class="size-3" />
                                        @endforeach
                                    </span>
                                @endif

                                @if ($post->media->isEmpty())
                                    <span aria-hidden="true">·</span>
                                    <span class="text-pending">No media</span>
                                @endif
                            </span>
                        </span>

                        <x-status-pill :status="$post->status" size="sm" class="hidden shrink-0 sm:inline-flex" />
                    </a>
                @empty
                    <x-empty-state icon="calendar" title="Nothing queued"
                                   body="Once posts are scheduled they appear here in the order they will go out." />
                @endforelse
            </x-card>

            {{-- Pipeline --------------------------------------------------- --}}
            <x-card title="{{ $pipeline['month'] }} pipeline"
                    subtitle="{{ $pipeline['total'] }} post{{ $pipeline['total'] === 1 ? '' : 's' }} scheduled this month, by status">
                @if ($pipeline['rows']->isEmpty())
                    <p class="text-small text-ink-500">Nothing is scheduled this month yet.</p>
                @else
                    <div class="space-y-2.5">
                        @foreach ($pipeline['rows'] as $row)
                            <a href="{{ route('posts.index', ['status' => $row['status']->value]) }}" wire:navigate
                               class="group flex items-center gap-3 rounded-control px-1 py-0.5 hover:bg-ink-050">

                                <span class="w-32 shrink-0 truncate text-small text-ink-700">
                                    <span aria-hidden="true" class="mr-1"
                                          style="color: var(--color-{{ $row['status']->token() }});">{{ $row['status']->glyph() }}</span>
                                    {{ $row['status']->label() }}
                                </span>

                                {{-- The bar carries magnitude; the count beside
                                     it carries the value. Colour is never the
                                     only channel. --}}
                                <span class="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-ink-100">
                                    <span class="block h-full rounded-full"
                                          style="width: {{ max($row['share'], 2) }}%;
                                                 background-color: var(--color-{{ $row['status']->token() }});"></span>
                                </span>

                                <span class="w-10 shrink-0 text-right text-small font-semibold text-ink-900"
                                      data-numeric>{{ $row['count'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>

        {{-- --------------------------------------------------- right column --}}
        <div class="min-w-0 space-y-4">

            {{-- Needs attention -------------------------------------------- --}}
            <x-card title="Needs attention"
                    subtitle="{{ count($attention) }} item{{ count($attention) === 1 ? '' : 's' }} to clear" flush>
                @forelse ($attention as $item)
                    <div class="flex items-start gap-2.5 border-b border-ink-100 px-5 py-3 last:border-0 hover:bg-ink-050">
                        <span class="mt-1 size-2 shrink-0 rounded-full"
                              style="background-color: var(--color-{{ $item['token'] }});"></span>

                        <a href="{{ $item['href'] }}" wire:navigate class="min-w-0 flex-1">
                            <span class="block truncate text-small font-semibold text-ink-900">{{ $item['title'] }}</span>
                            <span class="mt-0.5 block text-micro font-normal leading-snug text-ink-500">{{ $item['note'] }}</span>
                        </a>

                        <span class="flex shrink-0 items-center gap-2">
                            @if (($item['retry'] ?? null) && auth()->user()->can('publish-now'))
                                <button type="button" wire:click="retryTarget({{ $item['retry'] }})"
                                        wire:loading.attr="disabled" wire:target="retryTarget({{ $item['retry'] }})"
                                        class="rounded-control border border-ink-200 px-2 py-1 text-micro font-semibold
                                               text-ink-700 hover:border-signal hover:text-signal">
                                    <span wire:loading.remove wire:target="retryTarget({{ $item['retry'] }})">Retry</span>
                                    <span wire:loading wire:target="retryTarget({{ $item['retry'] }})">Retrying…</span>
                                </button>
                            @endif

                            <a href="{{ $item['href'] }}" wire:navigate
                               class="text-micro font-semibold text-signal">{{ $item['action'] }}</a>
                        </span>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center">
                        <span class="mx-auto flex size-9 items-center justify-center rounded-full bg-published-soft text-published">
                            <x-icon name="approvals" class="size-[18px]" />
                        </span>
                        <p class="mt-3 text-small font-semibold text-ink-900">All clear</p>
                        <p class="mt-0.5 text-small text-ink-500">Nothing failed, late or missing media.</p>
                    </div>
                @endforelse
            </x-card>

            {{-- Month coverage --------------------------------------------- --}}
            <x-card title="Coverage" subtitle="{{ $coverage['month'] }}">
                <div class="flex items-baseline gap-2">
                    <span class="text-h1 text-ink-900" data-numeric>{{ $coverage['percent'] }}%</span>
                    <span class="text-small text-ink-500">
                        <span data-numeric>{{ $coverage['covered'] }}</span> of
                        <span data-numeric>{{ $coverage['total'] }}</span> days have content
                    </span>
                </div>

                {{-- A mini month, not a doughnut: where the gaps fall matters
                     more than the percentage, and a run of empty days is what
                     the calendar flags. Monday-first, matching the calendar. --}}
                <div class="mt-3 grid max-w-[13.5rem] grid-cols-7 gap-1">
                    @foreach (['M', 'T', 'W', 'T', 'F', 'S', 'S'] as $initial)
                        <span class="text-center text-micro font-normal text-ink-300" aria-hidden="true">{{ $initial }}</span>
                    @endforeach

                    @for ($blank = 0; $blank < $coverage['lead']; $blank++)
                        <span></span>
                    @endfor

                    @foreach ($coverage['days'] as $day)
                        <span title="{{ $day['date'] }}{{ $day['filled'] ? '' : ' — empty' }}"
                              @class([
                                  'aspect-square rounded-[3px]',
                                  'ring-2 ring-signal ring-offset-1' => $day['today'],
                              ])
                              style="background-color: {{ $day['filled']
                                  ? 'var(--color-chart)'
                                  : ($day['past'] ? 'var(--color-ink-200)' : 'var(--color-ink-100)') }};"></span>
                    @endforeach
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-micro font-normal text-ink-500">
                    <span class="flex items-center gap-1.5">
                        <span class="size-2.5 rounded-[3px]" style="background-color: var(--color-chart);"></span>
                        Has content
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="size-2.5 rounded-[3px]" style="background-color: var(--color-ink-200);"></span>
                        Empty, already passed
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="size-2.5 rounded-[3px]" style="background-color: var(--color-ink-100);"></span>
                        Empty, still ahead
                    </span>
                </div>
            </x-card>

            {{-- Platform split --------------------------------------------- --}}
            <x-card title="Where it landed" subtitle="Destinations published, last 30 days">
                @php $anyPublished = collect($platforms)->sum('count') > 0; @endphp

                @if (! $anyPublished)
                    <p class="text-small text-ink-500">Nothing has published in the last 30 days.</p>
                @else
                    <div class="space-y-3">
                        @foreach ($platforms as $row)
                            <div>
                                <div class="flex items-center justify-between gap-2 text-small">
                                    <span class="flex min-w-0 items-center gap-1.5 text-ink-700">
                                        <x-platform-glyph :platform="$row['platform']" class="size-3.5 shrink-0" />
                                        <span class="truncate">{{ $row['platform']->label() }}</span>
                                    </span>
                                    <span class="shrink-0 font-semibold text-ink-900" data-numeric>
                                        {{ $row['count'] }} <span class="font-normal text-ink-500">· {{ $row['share'] }}%</span>
                                    </span>
                                </div>

                                <span class="mt-1.5 block h-2 overflow-hidden rounded-full bg-ink-100">
                                    <span class="block h-full rounded-full"
                                          style="width: {{ max($row['share'], 2) }}%;
                                                 background-color: var(--color-{{ $row['platform']->value }});"></span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>

            {{-- Connected accounts ----------------------------------------- --}}
            <x-card title="Connected accounts" flush>
                <x-slot:actions>
                    <a href="{{ route('settings.accounts') }}" wire:navigate
                       class="text-small font-semibold text-signal hover:underline">Manage</a>
                </x-slot:actions>

                @forelse ($accounts as $account)
                    @php $days = $account->tokenExpiresInDays(); @endphp

                    <div class="flex items-center gap-2.5 border-b border-ink-100 px-5 py-3 last:border-0">
                        <x-platform-glyph :platform="$account->platform" class="size-4 shrink-0" />

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-small font-semibold text-ink-900">{{ $account->displayName() }}</span>
                            <span class="block truncate text-micro font-normal text-ink-500">
                                @if ($days === null)
                                    Token does not expire
                                @elseif ($account->tokenHasExpired())
                                    Token expired
                                @else
                                    Token good for <span data-numeric>{{ $days }}</span> more day{{ $days === 1 ? '' : 's' }}
                                @endif
                            </span>
                        </span>

                        <span class="size-2 shrink-0 rounded-full"
                              style="background-color: var(--color-{{ $account->tokenNeedsAttention() ? 'pending' : 'published' }});"
                              title="{{ $account->tokenNeedsAttention() ? 'Needs reconnecting' : 'Healthy' }}"></span>
                    </div>
                @empty
                    <div class="px-5 py-6 text-center">
                        <p class="text-small font-semibold text-ink-900">No accounts connected</p>
                        <p class="mt-1 text-small text-ink-500">Nothing can publish until a Page is connected.</p>
                        <x-button :href="route('settings.accounts')" wire:navigate variant="primary" size="sm" class="mt-3">
                            Connect a Page
                        </x-button>
                    </div>
                @endforelse
            </x-card>

            {{-- Recent activity -------------------------------------------- --}}
            @can('view-activity-log')
                <x-card title="Recent activity" flush>
                    <x-slot:actions>
                        <a href="{{ route('activity') }}" wire:navigate
                           class="text-small font-semibold text-signal hover:underline">See all</a>
                    </x-slot:actions>

                    @forelse ($activity as $entry)
                        <div class="flex items-start gap-2.5 border-b border-ink-100 px-5 py-2.5 last:border-0">
                            <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-ink-050 text-micro font-semibold text-ink-500">
                                {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($entry->user?->name ?? 'Sy', 0, 2)) }}
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-small text-ink-700">
                                    <span class="font-semibold text-ink-900">{{ $entry->user?->name ?? 'System' }}</span>
                                    {{ str_replace(['.', '_'], ' ', $entry->action) }}
                                </span>
                                <time class="block text-micro font-normal text-ink-500" data-numeric>
                                    {{ $entry->created_at->copy()->setTimezone($tz)->diffForHumans() }}
                                </time>
                            </span>
                        </div>
                    @empty
                        <p class="px-5 py-6 text-center text-small text-ink-500">Nothing has happened yet.</p>
                    @endforelse
                </x-card>
            @endcan
        </div>
    </div>
</div>
