@php
    use App\Enums\Platform;
    use Illuminate\Support\Carbon;

    $today = Carbon::now($tz);
    $weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    $periodLabel = match ($view) {
        'week' => $days[0]->format('j M').' – '.end($days)->format('j M Y'),
        default => $cursorDate->format('F Y'),
    };
@endphp

<div>
    {{-- ================================================================= --}}
    {{-- Header                                                            --}}
    {{-- ================================================================= --}}
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-display">Calendar</h1>
            <p class="mt-0.5 text-small text-ink-500">
                <span data-numeric>{{ $total }}</span>
                {{ Str::plural('post', $total) }} in view, shown in <span data-numeric>{{ $tz }}</span>.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            {{-- View switcher --}}
            <div class="flex items-center rounded-control border border-ink-200 bg-white p-0.5">
                @foreach (['month' => 'Month', 'week' => 'Week', 'list' => 'List', 'kanban' => 'Board'] as $key => $label)
                    <button type="button" wire:click="setView('{{ $key }}')"
                            @class([
                                'rounded-[0.4rem] px-2.5 py-1.5 text-small font-semibold transition-colors',
                                'bg-signal-weak text-signal' => $view === $key,
                                'text-ink-500 hover:text-ink-900' => $view !== $key,
                            ])>{{ $label }}</button>
                @endforeach
            </div>

            @if (in_array($view, ['month', 'week'], true))
                <div class="flex items-center rounded-control border border-ink-200 bg-white">
                    <button type="button" wire:click="previous"
                            class="rounded-l-control px-2 py-2 text-ink-500 hover:bg-ink-050 hover:text-ink-900"
                            aria-label="Previous {{ $view }}">
                        <x-icon name="chevron-left" class="size-4" />
                    </button>
                    <span class="border-x border-ink-200 px-3 py-2 text-small font-semibold text-ink-900" data-numeric>
                        {{ $periodLabel }}
                    </span>
                    <button type="button" wire:click="next"
                            class="rounded-r-control px-2 py-2 text-ink-500 hover:bg-ink-050 hover:text-ink-900"
                            aria-label="Next {{ $view }}">
                        <x-icon name="chevron-right" class="size-4" />
                    </button>
                </div>
            @endif

            <x-button variant="secondary" wire:click="today">Today</x-button>

            <button type="button" wire:click="$toggle('filtersOpen')"
                    @class([
                        'inline-flex items-center gap-2 rounded-control border px-3 py-2 text-small font-semibold transition-colors',
                        'border-signal bg-signal-weak text-signal' => $this->hasFilters,
                        'border-ink-200 bg-white text-ink-700 hover:bg-ink-050' => ! $this->hasFilters,
                    ])>
                Filters
                @if ($this->hasFilters)
                    <span class="rounded-full bg-signal px-1.5 text-micro text-white" data-numeric>
                        {{ count($platforms) + count($statuses) + count($types) + ($author ? 1 : 0) }}
                    </span>
                @endif
            </button>

            {{-- Export what you are looking at. The filters live in the query
                 string, so the link simply carries them across. --}}
            <div x-data="{ open: false }" class="relative">
                <button type="button" @click="open = !open" :aria-expanded="open"
                        class="inline-flex items-center gap-2 rounded-control border border-ink-200 bg-white px-3 py-2
                               text-small font-semibold text-ink-700 hover:bg-ink-050 hover:text-ink-900">
                    <x-icon name="import" class="size-4 rotate-180" />
                    Export
                </button>

                <div x-show="open" x-cloak x-transition.opacity.duration.120ms
                     @click.outside="open = false" @keydown.escape.window="open = false"
                     class="absolute right-0 z-40 mt-1.5 w-64 rounded-card border border-ink-100 bg-white p-1.5 shadow-drawer">

                    @php $params = request()->only(['p', 's', 't', 'by']); @endphp

                    <a href="{{ route('exports.posts', array_merge($params, ['format' => 'xlsx'])) }}"
                       class="block rounded-control px-2.5 py-2 text-small text-ink-700 hover:bg-ink-050">
                        Spreadsheet (XLSX)
                        <span class="block text-micro text-ink-500">With outcomes, permalinks and metrics.</span>
                    </a>

                    <a href="{{ route('exports.posts', array_merge($params, ['format' => 'csv'])) }}"
                       class="block rounded-control px-2.5 py-2 text-small text-ink-700 hover:bg-ink-050">
                        Spreadsheet (CSV)
                    </a>

                    <div class="my-1 border-t border-ink-100"></div>

                    <a href="{{ route('exports.calendar', ['month' => $cursorDate->format('Y-m')]) }}"
                       class="block rounded-control px-2.5 py-2 text-small text-ink-700 hover:bg-ink-050">
                        Calendar PDF
                        <span class="block text-micro text-ink-500">A month grid for client sign-off.</span>
                    </a>
                </div>
            </div>

            <x-button variant="primary" icon="plus" :href="route('posts.create')">New post</x-button>
        </div>
    </div>

    {{-- ================================================================= --}}
    {{-- Filters                                                           --}}
    {{-- ================================================================= --}}
    @if ($filtersOpen)
        <div class="mb-4 rounded-card border border-ink-100 bg-white p-4">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">

                <div>
                    <p class="mb-1.5 text-micro font-semibold uppercase text-ink-500">Platform</p>
                    @foreach (Platform::cases() as $platform)
                        <label class="flex cursor-pointer items-center gap-2 py-1 text-small text-ink-700">
                            <input type="checkbox" wire:model.live="platforms" value="{{ $platform->value }}"
                                   class="size-4 rounded border-ink-300 text-signal focus:ring-signal">
                            <x-platform-glyph :platform="$platform" class="size-3.5" />
                            {{ $platform->label() }}
                        </label>
                    @endforeach
                </div>

                <div>
                    <p class="mb-1.5 text-micro font-semibold uppercase text-ink-500">Status</p>
                    <div class="max-h-40 overflow-y-auto pr-1">
                        @foreach ($statusOptions as $status)
                            <label class="flex cursor-pointer items-center gap-2 py-1 text-small text-ink-700">
                                <input type="checkbox" wire:model.live="statuses" value="{{ $status->value }}"
                                       class="size-4 rounded border-ink-300 text-signal focus:ring-signal">
                                <span aria-hidden="true" style="color: var(--color-{{ $status->token() }});">{{ $status->glyph() }}</span>
                                {{ $status->label() }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <div>
                    <p class="mb-1.5 text-micro font-semibold uppercase text-ink-500">Type</p>
                    @foreach ($typeOptions as $postType)
                        <label class="flex cursor-pointer items-center gap-2 py-1 text-small text-ink-700">
                            <input type="checkbox" wire:model.live="types" value="{{ $postType->value }}"
                                   class="size-4 rounded border-ink-300 text-signal focus:ring-signal">
                            <span aria-hidden="true">{{ $postType->glyph() }}</span>
                            {{ $postType->label() }}
                        </label>
                    @endforeach
                </div>

                <div>
                    <p class="mb-1.5 text-micro font-semibold uppercase text-ink-500">Author</p>
                    <select wire:model.live="author"
                            class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                   text-ink-900 focus:border-signal focus:outline-none">
                        <option value="">Anyone</option>
                        @foreach ($authors as $person)
                            <option value="{{ $person->id }}">{{ $person->name }}</option>
                        @endforeach
                    </select>

                    @if ($this->hasFilters)
                        <button type="button" wire:click="clearFilters"
                                class="mt-2 text-small font-semibold text-signal hover:text-signal-strong">
                            Clear all filters
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- ================================================================= --}}
    {{-- Month / week grid                                                 --}}
    {{-- ================================================================= --}}
    @if (in_array($view, ['month', 'week'], true))
        <div class="hidden overflow-hidden rounded-card border border-ink-100 bg-white md:block">

            <div class="grid grid-cols-7 border-b border-ink-100 bg-ink-050">
                @foreach ($weekdays as $weekday)
                    <div class="px-3 py-2.5 text-micro font-semibold uppercase text-ink-500">{{ $weekday }}</div>
                @endforeach
            </div>

            <div class="grid grid-cols-7">
                @foreach ($days as $i => $day)
                    @php
                        $key = $day->toDateString();
                        $inMonth = $view === 'week' || $day->month === $cursorDate->month;
                        $isToday = $day->isSameDay($today);
                        $dayPosts = $posts->get($key, collect());
                        $visible = $view === 'week' ? $dayPosts : $dayPosts->take(2);
                        $overflow = $dayPosts->count() - $visible->count();
                        $dayEvents = $events[$key] ?? [];
                        $gap = $gaps[$key] ?? null;
                    @endphp

                    <div class="cell-in relative flex flex-col gap-1.5 border-b border-r border-ink-100 p-2
                                {{ $loop->iteration % 7 === 0 ? 'border-r-0' : '' }}
                                {{ $view === 'week' ? 'min-h-88' : 'min-h-34' }}
                                {{ $inMonth ? 'bg-white' : 'bg-ink-050/60' }}"
                         style="animation-delay: {{ min($i * 8, 320) }}ms"
                         x-data="{ over: false }"
                         x-bind:class="over ? 'ring-2 ring-inset ring-signal bg-signal-weak' : ''"
                         x-on:dragover.prevent="over = true"
                         x-on:dragleave="over = false"
                         x-on:drop.prevent="over = false; $wire.reschedule(event.dataTransfer.getData('text/plain'), '{{ $key }}')">

                        {{-- UAE overlay: quiet background context, never a chip. --}}
                        @if ($dayEvents)
                            <div class="pointer-events-none absolute inset-x-0 top-0 h-full opacity-[0.06]"
                                 style="background: repeating-linear-gradient(135deg, var(--color-ink-900) 0 1px, transparent 1px 7px);"
                                 aria-hidden="true"></div>
                        @endif

                        <div class="relative flex items-start justify-between gap-1">
                            <span data-numeric
                                  class="inline-flex size-6 shrink-0 items-center justify-center rounded-full text-small font-semibold
                                         {{ $isToday ? 'bg-signal text-white' : ($inMonth ? 'text-ink-900' : 'text-ink-300') }}">
                                {{ $day->day }}
                            </span>

                            @if ($dayEvents)
                                <span class="truncate text-micro font-semibold text-ink-500"
                                      title="{{ collect($dayEvents)->pluck('name')->implode(', ') }}">
                                    {{ $dayEvents[0]->name }}{{ $dayEvents[0]->is_approximate ? '*' : '' }}
                                </span>
                            @endif
                        </div>

                        @foreach ($visible as $post)
                            <x-post-chip :post="$post" :tz="$tz" draggable
                                         class="relative"
                                         wire:key="chip-{{ $post->id }}"
                                         :href="route('posts.edit', $post)" />
                        @endforeach

                        @if ($overflow > 0)
                            <button type="button" wire:click="setView('list')"
                                    class="relative rounded-chip px-2 py-1 text-left text-micro font-semibold text-ink-500 hover:bg-ink-050 hover:text-ink-900">
                                +{{ $overflow }} more
                            </button>
                        @endif

                        {{-- Gap marker: a run of empty days is a planning
                             problem, so the calendar says so and offers a fix. --}}
                        @if ($gap)
                            <a href="{{ route('posts.create', ['date' => $gap['start']]) }}"
                               class="relative mt-auto rounded-chip border border-dashed border-ink-200 px-2 py-1
                                      text-left text-micro font-semibold text-ink-500 hover:border-signal hover:text-signal">
                                {{ $gap['length'] }} empty days · Fill this gap
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ================================================================= --}}
    {{-- List                                                              --}}
    {{-- ================================================================= --}}
    @if ($view === 'list')
        <div class="hidden overflow-hidden rounded-card border border-ink-100 bg-white md:block">
            @forelse ($posts as $date => $dayPosts)
                @php $day = Carbon::parse($date, $tz); @endphp

                <div class="border-b border-ink-100 last:border-0">
                    <div class="flex items-baseline gap-2 bg-ink-050 px-4 py-2">
                        <span class="text-small font-semibold text-ink-900" data-numeric>{{ $day->format('j M') }}</span>
                        <span class="text-small text-ink-500">{{ $day->format('l') }}</span>
                        @if ($day->isSameDay($today))
                            <span class="text-micro font-semibold text-signal">Today</span>
                        @endif
                    </div>

                    @foreach ($dayPosts as $post)
                        <a href="{{ route('posts.edit', $post) }}"
                           class="flex w-full items-center gap-3 border-b border-ink-100 px-4 py-2.5 text-left last:border-0 hover:bg-ink-050">
                            <time class="w-12 shrink-0 text-small font-semibold text-ink-500"
                                  datetime="{{ $post->scheduled_at->toIso8601String() }}">
                                {{ $post->scheduled_at->copy()->setTimezone($tz)->format('H:i') }}
                            </time>

                            <span class="min-w-0 flex-1 truncate text-body text-ink-900" dir="auto">
                                {{ $post->title ?: Str::limit(strip_tags((string) $post->caption), 70) }}
                            </span>

                            <span class="flex shrink-0 items-center gap-1">
                                @foreach ($post->targets->map(fn ($t) => $t->socialAccount?->platform)->filter()->unique(fn ($p) => $p->value) as $platform)
                                    <x-platform-glyph :platform="$platform" class="size-3.5" />
                                @endforeach
                            </span>

                            <x-status-pill :status="$post->status" size="sm" class="shrink-0" />
                        </a>
                    @endforeach
                </div>
            @empty
                <x-empty-state title="Nothing in this month"
                               body="Add a post, or clear your filters if you expected to see something."
                               icon="calendar" />
            @endforelse
        </div>
    @endif

    {{-- ================================================================= --}}
    {{-- Kanban by status                                                  --}}
    {{-- ================================================================= --}}
    @if ($view === 'kanban')
        @php
            $columns = collect($statusOptions)->filter(
                fn ($status) => $posts->flatten()->contains(fn ($p) => $p->status === $status)
            );
            $columns = $columns->isEmpty() ? collect($statusOptions)->take(4) : $columns;
        @endphp

        <div class="hidden gap-3 overflow-x-auto pb-2 md:flex">
            @foreach ($columns as $status)
                @php $columnPosts = $posts->flatten()->filter(fn ($p) => $p->status === $status)->values(); @endphp

                <div class="w-64 shrink-0 rounded-card border border-ink-100 bg-white">
                    <div class="flex items-center justify-between gap-2 border-b border-ink-100 px-3 py-2.5">
                        <x-status-pill :status="$status" size="sm" />
                        <span class="text-small font-semibold text-ink-500" data-numeric>{{ $columnPosts->count() }}</span>
                    </div>

                    <div class="space-y-1.5 p-2">
                        @forelse ($columnPosts as $post)
                            <x-post-chip :post="$post" :tz="$tz"
                                         wire:key="kanban-{{ $post->id }}"
                                         :href="route('posts.edit', $post)" />
                        @empty
                            <p class="px-1 py-3 text-small text-ink-500">Nothing here.</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ================================================================= --}}
    {{-- Mobile: day-grouped agenda, whatever the desktop view             --}}
    {{-- ================================================================= --}}
    <div class="space-y-4 md:hidden">
        @forelse ($posts as $date => $dayPosts)
            @php $day = Carbon::parse($date, $tz); @endphp

            <section>
                <h2 class="mb-1.5 flex items-baseline gap-2 px-0.5">
                    <span class="text-h2 {{ $day->isSameDay($today) ? 'text-signal' : '' }}" data-numeric>
                        {{ $day->format('j M') }}
                    </span>
                    <span class="text-small text-ink-500">{{ $day->format('l') }}</span>
                    @if ($day->isSameDay($today))
                        <span class="text-micro font-semibold text-signal">Today</span>
                    @endif
                    @if (! empty($events[$date]))
                        <span class="ml-auto truncate text-micro font-semibold text-ink-500">
                            {{ $events[$date][0]->name }}
                        </span>
                    @endif
                </h2>

                <div class="space-y-1.5">
                    @foreach ($dayPosts as $post)
                        <x-post-chip :post="$post" :tz="$tz" class="py-2!"
                                     wire:key="m-{{ $post->id }}"
                                     :href="route('posts.edit', $post)" />
                    @endforeach
                </div>
            </section>
        @empty
            <x-card flush>
                <x-empty-state title="Nothing scheduled"
                               body="Add your first post, or import the calendar you already keep in a spreadsheet."
                               icon="calendar">
                    <x-slot:actions>
                        <x-button variant="primary" icon="plus" :href="route('posts.create')">New post</x-button>
                        <x-button variant="secondary" :href="route('import.index')">Import a spreadsheet</x-button>
                    </x-slot:actions>
                </x-empty-state>
            </x-card>
        @endforelse
    </div>

    {{-- Floating compose button, mobile only. --}}
    <a href="{{ route('posts.create') }}"
       class="fixed bottom-20 right-4 z-20 flex size-13 items-center justify-center rounded-full bg-signal
              text-white shadow-drawer hover:bg-signal-strong md:hidden"
       aria-label="New post">
        <x-icon name="plus" class="size-6" />
    </a>

    {{-- Status legend. Colour is never the only signal. --}}
    <div class="mt-4 hidden flex-wrap items-center gap-x-4 gap-y-2 px-1 md:flex">
        @foreach ($statusOptions as $status)
            <span class="flex items-center gap-1.5 text-micro font-semibold text-ink-500">
                <span aria-hidden="true" style="color: var(--color-{{ $status->token() }});">{{ $status->glyph() }}</span>
                {{ $status->label() }}
            </span>
        @endforeach

        @if ($events)
            <span class="flex items-center gap-1.5 text-micro text-ink-500">
                <span class="inline-block size-3 rounded-sm"
                      style="background: repeating-linear-gradient(135deg, var(--color-ink-300) 0 1px, transparent 1px 4px);"
                      aria-hidden="true"></span>
                UAE calendar · <span class="text-ink-300">* date approximate</span>
            </span>
        @endif
    </div>
</div>
