@php
    $steps = [
        1 => 'Upload',
        2 => 'Map columns',
        3 => 'Check the dry run',
        4 => 'Done',
    ];
@endphp

{{--
    One measured column for the whole wizard, left-aligned with the page
    title. A stepper stretched across 1900px reads as four unrelated dots.
--}}
<div class="max-w-4xl space-y-5">

    {{-- ================================================================= --}}
    {{-- Stepper. The only one in the product, because this is the only    --}}
    {{-- genuine sequence: each step depends on the last.                  --}}
    {{-- ================================================================= --}}
    <ol class="flex items-center gap-1 sm:gap-2">
        @foreach ($steps as $number => $label)
            @php
                $state = $step > $number ? 'done' : ($step === $number ? 'current' : 'todo');
            @endphp

            <li class="flex min-w-0 flex-1 items-center gap-2">
                <span @class([
                    'flex size-7 shrink-0 items-center justify-center rounded-full text-micro font-semibold transition-colors',
                    'bg-published text-white' => $state === 'done',
                    'bg-signal text-white ring-4 ring-signal-weak' => $state === 'current',
                    'bg-ink-100 text-ink-500' => $state === 'todo',
                ]) data-numeric>{{ $state === 'done' ? '✓' : $number }}</span>

                <span @class([
                    'hidden truncate text-small font-semibold sm:block',
                    'text-ink-900' => $state === 'current',
                    'text-published' => $state === 'done',
                    'text-ink-500' => $state === 'todo',
                ])>{{ $label }}</span>

                @unless ($loop->last)
                    {{-- The connector fills the gap, so progress reads as one
                         line rather than four disconnected dots. --}}
                    <span aria-hidden="true" @class([
                        'ml-1 h-px min-w-4 flex-1 rounded-full',
                        'bg-published' => $state === 'done',
                        'bg-ink-200' => $state !== 'done',
                    ])></span>
                @endunless
            </li>
        @endforeach
    </ol>

    {{-- ================================================================= --}}
    {{-- Step 1 — upload                                                   --}}
    {{-- ================================================================= --}}
    @if ($step === 1)
        <div class="space-y-5">
            <x-card title="Upload your calendar"
                    subtitle="CSV or Excel. Nothing is created until you have seen a per-row verdict.">

                {{--
                    A real drop target rather than a bare file input. The native
                    control is hidden behind a label: "Choose File / No file
                    chosen" is the browser wording, not ours, and it cannot be
                    styled.
                --}}
                <div x-data="{
                        dragging: false,
                        drop(event) {
                            this.dragging = false;
                            const input = $refs.input;
                            if (event.dataTransfer.files.length) {
                                input.files = event.dataTransfer.files;
                                input.dispatchEvent(new Event('change', { bubbles: true }));
                            }
                        }
                     }"
                     x-on:dragover.prevent="dragging = true"
                     x-on:dragleave.prevent="dragging = false"
                     x-on:drop.prevent="drop($event)">

                    <label for="file"
                           class="flex cursor-pointer flex-col items-center justify-center gap-3 rounded-card border-2
                                  border-dashed px-6 py-10 text-center transition-colors"
                           x-bind:class="dragging
                               ? 'border-signal bg-signal-weak'
                               : 'border-ink-200 hover:border-signal hover:bg-ink-050'">

                        <span class="flex size-12 items-center justify-center rounded-full bg-signal-weak text-signal">
                            <x-icon name="import" class="size-6" />
                        </span>

                        <span class="space-y-1">
                            <span class="block text-h2 text-ink-900">Drop your spreadsheet here</span>
                            <span class="block text-small text-ink-500">
                                or <span class="font-semibold text-signal">browse for a file</span>
                            </span>
                        </span>

                        <span class="text-micro text-ink-500">CSV, XLSX or XLS · up to 20MB</span>

                        <input type="file" wire:model="file" id="file" x-ref="input"
                               accept=".csv,.xlsx,.xls,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                               class="sr-only">
                    </label>
                </div>

                <div wire:loading wire:target="file"
                     class="mt-3 flex items-center gap-2.5 rounded-control border border-signal-edge bg-signal-weak px-3 py-2.5">
                    <span class="size-4 shrink-0 animate-spin rounded-full border-2 border-signal border-t-transparent"></span>
                    <span class="text-small font-semibold text-signal">Uploading…</span>
                </div>

                {{-- What was picked, so the next click is not a leap of faith. --}}
                @if ($file)
                    <div wire:loading.remove wire:target="file"
                         class="mt-3 flex items-center gap-3 rounded-control border border-ink-200 bg-ink-050 px-3 py-2.5">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-control bg-white text-ink-500">
                            <x-icon name="posts" class="size-4" />
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-small font-semibold text-ink-900">
                                {{ $file->getClientOriginalName() }}
                            </span>
                            <span class="block text-micro text-ink-500" data-numeric>
                                {{ number_format($file->getSize() / 1024) }} KB
                            </span>
                        </span>

                        <label for="file"
                               class="shrink-0 cursor-pointer rounded-control px-2 py-1 text-micro font-semibold
                                      text-ink-500 hover:bg-white hover:text-ink-900">
                            Change
                        </label>
                    </div>
                @endif

                @error('file')
                    <p class="mt-3 flex items-start gap-2 rounded-control border border-failed/25 bg-failed-soft px-3 py-2.5 text-small text-failed">
                        <span aria-hidden="true" class="mt-px">✕</span>
                        <span>{{ $message }}</span>
                    </p>
                @enderror

                <div class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-ink-100 pt-4">
                    <x-button variant="primary" wire:click="uploadFile"
                              wire:loading.attr="disabled" wire:target="uploadFile,file">
                        <span wire:loading.remove wire:target="uploadFile">Read the file</span>
                        <span wire:loading wire:target="uploadFile">Reading…</span>
                    </x-button>

                    <p class="text-small text-ink-500">Next you confirm what each column means.</p>
                </div>
            </x-card>

            {{-- The shape it already has, so nobody reformats anything first. --}}
            <x-card title="Your existing sheet works as-is"
                    subtitle="These headings are recognised automatically. Anything else you map by hand.">
                <div class="flex flex-wrap gap-1.5">
                    @foreach (['Date', 'Day', 'Type', 'Status', 'Graphic Link', 'Content'] as $column)
                        <span class="rounded-full border border-ink-200 bg-white px-2.5 py-1 text-micro font-semibold text-ink-700">
                            {{ $column }}
                        </span>
                    @endforeach
                </div>

                <p class="mt-3 text-small text-ink-500">
                    Dates are never guessed. You will be asked whether 03-04 means 3 April or 4 March,
                    because getting that wrong puts a month of content on the wrong days.
                </p>
            </x-card>
        </div>
    @endif

    {{-- ================================================================= --}}
    {{-- Step 2 — map columns                                              --}}
    {{-- ================================================================= --}}
    @if ($step === 2)
        <x-card title="Map your columns"
                subtitle="We have guessed from the headings. Correct anything that looks wrong.">

            <div class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($fields as $key => $definition)
                        <div class="space-y-1.5">
                            <label for="map-{{ $key }}" class="block text-small font-semibold text-ink-900">
                                {{ $definition['label'] }}
                                @if ($definition['required'])
                                    <span class="ml-1 font-normal text-failed">required</span>
                                @else
                                    <span class="ml-1 font-normal text-ink-500">optional</span>
                                @endif
                            </label>

                            <select id="map-{{ $key }}" wire:model="mapping.{{ $key }}"
                                    class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                           text-ink-900 focus:border-signal focus:outline-none">
                                <option value="">— not in this file —</option>
                                @foreach ($headers as $index => $header)
                                    <option value="{{ $index }}">{{ $header !== '' ? $header : 'Column '.($index + 1) }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>

                @error('mapping')
                    <p class="text-small text-failed">{{ $message }}</p>
                @enderror

                <div class="grid gap-4 border-t border-ink-100 pt-4 sm:grid-cols-2">
                    {{-- Never inferred: 03-04-2026 is two different days
                         depending on who made the sheet. --}}
                    <div class="space-y-1.5">
                        <span class="block text-small font-semibold text-ink-900">
                            How are the dates written? <span class="ml-1 font-normal text-failed">required</span>
                        </span>

                        @foreach (['DD-MM-YYYY' => 'Day first — 03-04-2026 is 3 April', 'MM-DD-YYYY' => 'Month first — 03-04-2026 is 4 March'] as $value => $label)
                            <label class="flex cursor-pointer items-start gap-2.5 rounded-control border px-3 py-2
                                          {{ $dateFormat === $value ? 'border-signal bg-signal-weak' : 'border-ink-200 hover:bg-ink-050' }}">
                                <input type="radio" wire:model.live="dateFormat" value="{{ $value }}"
                                       class="mt-0.5 size-4 border-ink-300 text-signal focus:ring-signal">
                                <span class="text-small {{ $dateFormat === $value ? 'text-signal font-semibold' : 'text-ink-700' }}">
                                    {{ $label }}
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <div class="space-y-4">
                        <x-field label="Default publish time" name="defaultTime" required
                                 hint="Used for any row without its own time.">
                            <x-slot:control>
                                <input id="defaultTime" type="time" wire:model="defaultTime"
                                       class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-body
                                              tabular-nums text-ink-900 focus:border-signal focus:outline-none">
                            </x-slot:control>
                        </x-field>

                        <div class="space-y-1.5">
                            <span class="block text-small font-semibold text-ink-900">
                                Publish these to <span class="ml-1 font-normal text-failed">required</span>
                            </span>

                            @foreach ($accounts as $account)
                                <label class="flex cursor-pointer items-center gap-2.5 rounded-control border border-ink-200 px-3 py-2 hover:bg-ink-050">
                                    <input type="checkbox" wire:model="accountIds" value="{{ $account->id }}"
                                           class="size-4 rounded border-ink-300 text-signal focus:ring-signal">
                                    <x-platform-glyph :platform="$account->platform" class="size-4" />
                                    <span class="truncate text-small text-ink-900">{{ $account->name }}</span>
                                </label>
                            @endforeach

                            @error('accountIds')
                                <p class="text-small text-failed">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 border-t border-ink-100 pt-4">
                    <x-button variant="primary" wire:click="confirmMapping">Run the dry run</x-button>
                    <x-button variant="ghost" wire:click="startOver">Start over</x-button>
                </div>
            </div>
        </x-card>
    @endif

    {{-- ================================================================= --}}
    {{-- Step 3 — dry run                                                  --}}
    {{-- ================================================================= --}}
    @if ($step === 3 && $summary)
        <x-card title="Dry run"
                subtitle="Nothing has been created yet. Rows with an error are skipped; you can download them afterwards.">

            <div class="space-y-4">
                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach ([
                        ['ready', 'Ready', 'published'],
                        ['warning', 'With warnings', 'pending'],
                        ['error', 'Will be skipped', 'failed'],
                    ] as [$key, $label, $token])
                        <button type="button" wire:click="$set('verdictFilter', '{{ $verdictFilter === $key ? 'all' : $key }}')"
                                class="rounded-card border p-3 text-left transition-colors
                                       {{ $verdictFilter === $key ? 'border-signal' : 'border-ink-100 hover:border-ink-200' }}"
                                style="background-color: var(--color-{{ $token }}-soft);">
                            <span class="block text-h1" data-numeric style="color: var(--color-{{ $token }});">
                                {{ $summary[$key] }}
                            </span>
                            <span class="text-small font-semibold" style="color: var(--color-{{ $token }});">{{ $label }}</span>
                        </button>
                    @endforeach
                </div>

                @if ($verdictFilter !== 'all')
                    <button type="button" wire:click="$set('verdictFilter', 'all')"
                            class="text-small font-semibold text-signal">Show all {{ $summary['total'] }} rows</button>
                @endif

                <div class="overflow-x-auto rounded-card border border-ink-100">
                    <table class="w-full min-w-[46rem] text-left">
                        <thead class="bg-ink-050">
                            <tr>
                                <th class="px-3 py-2 text-micro font-semibold uppercase text-ink-500">Row</th>
                                <th class="px-3 py-2 text-micro font-semibold uppercase text-ink-500">When</th>
                                <th class="px-3 py-2 text-micro font-semibold uppercase text-ink-500">Caption</th>
                                <th class="px-3 py-2 text-micro font-semibold uppercase text-ink-500">Verdict</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($visibleVerdicts as $verdict)
                                @php
                                    $token = match ($verdict['status']) {
                                        'ready' => 'published',
                                        'warning' => 'pending',
                                        default => 'failed',
                                    };
                                    $glyph = match ($verdict['status']) {
                                        'ready' => '●',
                                        'warning' => '◐',
                                        default => '✕',
                                    };
                                @endphp

                                <tr class="border-t border-ink-100">
                                    <td class="px-3 py-2 text-small text-ink-500" data-numeric>{{ $verdict['line'] }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-small text-ink-900" data-numeric>
                                        {{ $verdict['date'] ?? '—' }}
                                    </td>
                                    <td class="max-w-sm px-3 py-2 text-small text-ink-700" dir="auto">
                                        {{ $verdict['caption'] ?: '—' }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="flex items-start gap-1.5 text-small" style="color: var(--color-{{ $token }});">
                                            <span aria-hidden="true" class="mt-px">{{ $glyph }}</span>
                                            <span>{{ $verdict['reason'] ?: 'Ready to import.' }}</span>
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-3 py-6 text-center text-small text-ink-500">
                                    No rows in this category.
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center gap-2 border-t border-ink-100 pt-4">
                    <x-button variant="primary" wire:click="commit" wire:loading.attr="disabled" wire:target="commit">
                        <span wire:loading.remove wire:target="commit">
                            Import {{ $summary['ready'] + $summary['warning'] }} {{ Str::plural('row', $summary['ready'] + $summary['warning']) }}
                        </span>
                        <span wire:loading wire:target="commit">Importing…</span>
                    </x-button>

                    <x-button variant="secondary" wire:click="backToMapping">Back to mapping</x-button>
                    <x-button variant="ghost" wire:click="startOver">Start over</x-button>
                </div>
            </div>
        </x-card>
    @endif

    {{-- ================================================================= --}}
    {{-- Step 4 — done                                                     --}}
    {{-- ================================================================= --}}
    @if ($step === 4 && $batch)
        <x-card>
            <div class="space-y-4 text-center">
                <p class="text-display text-published" data-numeric>{{ $batch->rows_imported }}</p>
                <p class="text-h2">{{ Str::plural('post', $batch->rows_imported) }} imported</p>

                <p class="mx-auto max-w-[46ch] text-small text-ink-500">
                    Media is downloading in the background. Anything with a Drive link will show as fetching
                    on the calendar until it is ready.
                </p>

                @if ($batch->rows_failed > 0)
                    <div class="mx-auto max-w-md rounded-control border border-pending/25 bg-pending-soft p-3 text-left">
                        <p class="text-small font-semibold text-pending">
                            {{ $batch->rows_failed }} {{ Str::plural('row', $batch->rows_failed) }} were skipped
                        </p>
                        <p class="mt-1 text-small text-ink-700">
                            Download them in the original shape, fix them, and upload again.
                        </p>
                        <button type="button" wire:click="downloadErrors"
                                class="mt-2 text-small font-semibold text-signal hover:text-signal-strong">
                            Download the skipped rows
                        </button>
                    </div>
                @endif

                <div class="flex flex-wrap items-center justify-center gap-2 pt-2">
                    <x-button variant="primary" :href="route('calendar')">Open the calendar</x-button>
                    <x-button variant="secondary" wire:click="startOver">Import another file</x-button>
                    <x-button variant="danger" wire:click="undo"
                              wire:confirm="Remove the {{ $batch->rows_imported }} posts this import created? Anything already published is kept.">
                        Undo this import
                    </x-button>
                </div>
            </div>
        </x-card>
    @endif
</div>
