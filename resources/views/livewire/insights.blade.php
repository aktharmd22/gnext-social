@php
    use Illuminate\Support\Carbon;

    $weekdays = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /*
     * Sequential ramp: ONE hue, light to dark, strictly monotonic in lightness.
     * Every step carries a text colour that clears 4.5:1 against it -- ink for
     * the light steps, white for the two darkest -- so the number in each cell
     * is always legible and colour is never the only signal.
     */
    $ramp = [
        ['#EAF1FE', 'var(--color-ink-900)'],
        ['#C7DAFC', 'var(--color-ink-900)'],
        ['#93B4F8', 'var(--color-ink-900)'],
        ['#5C8AF1', 'var(--color-ink-900)'],
        ['#2563EB', '#FFFFFF'],
        ['#1A3FA8', '#FFFFFF'],
    ];

    $max = $heatmap['max'] ?: 1;

    $stepFor = function (?float $rate) use ($max, $ramp) {
        if ($rate === null) {
            return null;
        }

        $index = (int) round(($rate / $max) * (count($ramp) - 1));

        return $ramp[max(0, min(count($ramp) - 1, $index))];
    };
@endphp

<div class="space-y-4">

    {{-- ================================================================= --}}
    {{-- Range                                                             --}}
    {{-- ================================================================= --}}
    <div class="flex flex-wrap items-center gap-2">
        <span class="text-small text-ink-500">Showing</span>
        <div class="flex items-center rounded-control border border-ink-200 bg-white p-0.5">
            @foreach ([30 => '30 days', 90 => '90 days', 180 => '6 months', 365 => 'A year'] as $days => $label)
                <button type="button" wire:click="setRange({{ $days }})"
                        @class([
                            'rounded-[0.4rem] px-2.5 py-1.5 text-small font-semibold transition-colors',
                            'bg-signal-weak text-signal' => $range === $days,
                            'text-ink-500 hover:text-ink-900' => $range !== $days,
                        ])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- ================================================================= --}}
    {{-- Stat tiles. Hero numbers, not charts: a single value has no shape --}}
    {{-- worth plotting.                                                   --}}
    {{-- ================================================================= --}}
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @php
            $tiles = [
                ['Posts published', $postsPublished, null, 'Across ' . $destinationsPublished . ' ' . Str::plural('destination', $destinationsPublished)],
                ['Success rate', $successRate !== null ? $successRate . '%' : '—', $successRate !== null && $successRate < 100 ? 'fall' : null, $failedCount > 0 ? $failedCount . ' ' . Str::plural('failure', $failedCount) : 'Nothing failed'],
                ['Median engagement', $medianRate !== null ? $medianRate . '%' : '—', null, 'Of reach, per post'],
                ['Total reach', number_format($totalReach), null, 'People reached'],
            ];
        @endphp

        @foreach ($tiles as [$label, $value, $tone, $foot])
            <x-card>
                <p class="text-small text-ink-500">{{ $label }}</p>
                <p class="mt-1 text-display {{ $tone === 'fall' ? 'text-fall' : 'text-ink-900' }}" data-numeric>
                    {{ $value }}
                </p>
                <p class="mt-1 text-small text-ink-500">{{ $foot }}</p>
            </x-card>
        @endforeach
    </div>

    <div class="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">

        {{-- ============================================================= --}}
        {{-- Heatmap: weekday x hour, sequential magnitude                 --}}
        {{-- ============================================================= --}}
        <x-card title="When this audience engages"
                subtitle="Median engagement rate by local weekday and hour.">

            <x-slot:actions>
                <button type="button" wire:click="$toggle('showTable')"
                        class="text-small font-semibold text-signal hover:text-signal-strong">
                    {{ $showTable ? 'Show the grid' : 'Show as a table' }}
                </button>
            </x-slot:actions>

            @if ($heatmap['samples'] < 5)
                <x-empty-state
                    title="Not enough measured posts yet"
                    body="Metrics are captured 24 hours, 7 days and 30 days after each post. Once a handful have been measured, the pattern appears here."
                    icon="insights" />
            @elseif ($showTable)
                {{-- The table view is the accessibility floor: the same numbers
                     with no reliance on colour at all. --}}
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-small">
                        <thead>
                            <tr class="border-b border-ink-100">
                                <th class="py-2 pr-3 text-micro font-semibold uppercase text-ink-500">Day</th>
                                <th class="py-2 pr-3 text-micro font-semibold uppercase text-ink-500">Hour</th>
                                <th class="py-2 pr-3 text-micro font-semibold uppercase text-ink-500">Posts</th>
                                <th class="py-2 text-micro font-semibold uppercase text-ink-500">Engagement</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($weekdays as $index => $name)
                                @foreach (($heatmap['grid'][$index] ?? []) as $hour => $bucket)
                                    <tr class="border-b border-ink-100 last:border-0">
                                        <td class="py-1.5 pr-3 text-ink-900">{{ $name }}</td>
                                        <td class="py-1.5 pr-3 text-ink-700" data-numeric>{{ sprintf('%02d:00', $hour) }}</td>
                                        <td class="py-1.5 pr-3 text-ink-500" data-numeric>{{ $bucket['posts'] }}</td>
                                        <td class="py-1.5 font-semibold text-ink-900" data-numeric>{{ $bucket['rate'] }}%</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="overflow-x-auto">
                    <div class="min-w-176">
                        {{-- Hour axis --}}
                        <div class="flex gap-px pl-10">
                            @for ($hour = 0; $hour < 24; $hour++)
                                <div class="flex-1 pb-1 text-center text-micro text-ink-500" data-numeric>
                                    {{ $hour % 3 === 0 ? sprintf('%02d', $hour) : '' }}
                                </div>
                            @endfor
                        </div>

                        @foreach ($weekdays as $index => $name)
                            <div class="flex items-center gap-px">
                                <div class="w-10 shrink-0 pr-2 text-right text-micro font-semibold text-ink-500">
                                    {{ $name }}
                                </div>

                                @for ($hour = 0; $hour < 24; $hour++)
                                    @php
                                        $bucket = $heatmap['grid'][$index][$hour] ?? null;
                                        $step = $stepFor($bucket['rate'] ?? null);
                                    @endphp

                                    <div class="group relative h-8 flex-1"
                                         style="background-color: {{ $step[0] ?? 'var(--color-ink-050)' }};">

                                        @if ($bucket)
                                            {{-- The value, not just a shade. --}}
                                            <span class="flex size-full items-center justify-center text-micro font-semibold"
                                                  style="color: {{ $step[1] }};" data-numeric>
                                                {{ round($bucket['rate']) }}
                                            </span>

                                            {{-- Hover layer: an HTML chart is interactive by default. --}}
                                            <span class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-1 hidden
                                                         w-max -translate-x-1/2 rounded-control bg-ink-900 px-2 py-1
                                                         text-micro text-white group-hover:block">
                                                {{ $name }} {{ sprintf('%02d:00', $hour) }} ·
                                                {{ $bucket['rate'] }}% from {{ $bucket['posts'] }}
                                                {{ Str::plural('post', $bucket['posts']) }}
                                            </span>
                                        @endif
                                    </div>
                                @endfor
                            </div>
                        @endforeach

                        {{-- Legend --}}
                        <div class="mt-3 flex items-center gap-2 pl-10">
                            <span class="text-micro text-ink-500">Lower</span>
                            @foreach ($ramp as [$fill])
                                <span class="h-3 w-6 rounded-sm" style="background-color: {{ $fill }};" aria-hidden="true"></span>
                            @endforeach
                            <span class="text-micro text-ink-500">Higher engagement</span>
                            <span class="ml-auto text-micro text-ink-500" data-numeric>
                                {{ $heatmap['samples'] }} measured {{ Str::plural('post', $heatmap['samples']) }}
                            </span>
                        </div>
                    </div>
                </div>
            @endif
        </x-card>

        {{-- ============================================================= --}}
        {{-- Side column                                                   --}}
        {{-- ============================================================= --}}
        <div class="min-w-0 space-y-4">
            @if ($best)
                <x-card title="Best performing">
                    <p class="text-body font-semibold text-ink-900" dir="auto">
                        {{ $best->post?->title ?: Str::limit((string) $best->post?->caption, 60) }}
                    </p>

                    <p class="mt-1.5 flex items-center gap-1.5 text-small text-ink-500">
                        @if ($best->socialAccount)
                            <x-platform-glyph :platform="$best->socialAccount->platform" class="size-3.5" />
                            {{ $best->socialAccount->name }}
                        @endif
                        @if ($best->published_at)
                            · <time data-numeric>{{ $best->published_at->copy()->setTimezone($tz)->format('j M') }}</time>
                        @endif
                    </p>

                    {{-- Two decimals: the stored precision is for maths, not
                         for reading. --}}
                    <p class="mt-3 text-h1 text-published" data-numeric>{{ number_format((float) $bestRate, 2) }}%</p>
                    <p class="text-small text-ink-500">engagement of reach</p>

                    @if ($best->permalink)
                        <a href="{{ $best->permalink }}" target="_blank" rel="noopener noreferrer"
                           class="mt-3 inline-block text-small font-semibold text-signal hover:text-signal-strong">
                            View it on {{ $best->socialAccount?->platform->label() }} →
                        </a>
                    @endif
                </x-card>
            @endif

            @if ($bestSlots->isNotEmpty())
                <x-card title="Suggested times"
                        subtitle="From your own measured history, not folklore.">
                    <ul class="space-y-2">
                        @foreach ($bestSlots as $slot)
                            <li class="flex items-baseline justify-between gap-3">
                                <span class="text-small text-ink-900" data-numeric>{{ $slot['label'] }}</span>
                                <span class="shrink-0 text-small font-semibold text-published" data-numeric>
                                    {{ number_format((float) $slot['rate'], 2) }}%
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    <p class="mt-3 border-t border-ink-100 pt-2 text-small text-ink-500">
                        A hint, not a rule. These appear in the composer when you pick a time.
                    </p>
                </x-card>
            @endif

            @if ($recentFailures->isNotEmpty())
                <x-card title="Recent failures">
                    <ul class="space-y-3">
                        @foreach ($recentFailures as $failure)
                            <li>
                                <p class="truncate text-small font-semibold text-ink-900" dir="auto">
                                    {{ $failure->post?->title ?: Str::limit((string) $failure->post?->caption, 40) }}
                                </p>
                                <p class="mt-0.5 text-small text-failed">{{ $failure->error_message }}</p>
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endif
        </div>
    </div>
</div>
