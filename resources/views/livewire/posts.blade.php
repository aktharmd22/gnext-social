@php
    $anySelected = count($selected) > 0;
    $hasFilters = $search !== '' || $status !== '' || $types !== [] || $author !== null;
@endphp

<div class="space-y-4">

    {{-- ================================================================= --}}
    {{-- Filters                                                           --}}
    {{-- ================================================================= --}}
    <div class="flex flex-wrap items-center gap-2">
        <label class="relative flex min-w-0 flex-1 items-center sm:max-w-xs">
            <span class="sr-only">Search posts</span>
            <x-icon name="search" class="pointer-events-none absolute left-3 size-4 text-ink-500" />
            <input type="search" wire:model.live.debounce.400ms="search"
                   placeholder="Search captions and titles"
                   class="w-full rounded-control border border-ink-200 bg-white py-2 pl-9 pr-3 text-small
                          text-ink-900 placeholder:text-ink-500 focus:border-signal focus:outline-none">
        </label>

        <select wire:model.live="author"
                class="min-w-0 flex-1 rounded-control sm:flex-none border border-ink-200 bg-white px-3 py-2 text-small text-ink-900 focus:border-signal focus:outline-none">
            <option value="">Anyone</option>
            @foreach ($authors as $person)
                <option value="{{ $person->id }}">{{ $person->name }}</option>
            @endforeach
        </select>

        <select wire:model.live="status"
                class="min-w-0 flex-1 rounded-control sm:flex-none border border-ink-200 bg-white px-3 py-2 text-small text-ink-900 focus:border-signal focus:outline-none">
            <option value="">Any status</option>
            @foreach ($statusOptions as $option)
                <option value="{{ $option->value }}">{{ $option->glyph() }} {{ $option->label() }}</option>
            @endforeach
        </select>

        @if ($hasFilters)
            <button type="button" wire:click="clearFilters"
                    class="text-small font-semibold text-signal hover:text-signal-strong">Clear</button>
        @endif

        <x-button variant="primary" icon="plus" :href="route('posts.create', ['from' => 'posts'])" class="sm:ml-auto">New post</x-button>
    </div>

    {{-- ================================================================= --}}
    {{-- Bulk action bar. Appears only when something is selected.          --}}
    {{-- ================================================================= --}}
    @if ($anySelected)
        <div class="sticky top-2 z-20 flex flex-wrap items-center gap-2 rounded-card border border-signal-edge bg-signal-weak px-3 py-2.5">
            <span class="text-small font-semibold text-signal" data-numeric>
                {{ count($selected) }} selected
            </span>

            <span class="mx-1 hidden h-4 w-px bg-signal-edge sm:block"></span>

            {{-- Shift by days --}}
            <div class="flex items-center gap-1">
                <input type="number" wire:model="shiftDays" min="-365" max="365"
                       class="w-16 rounded-control border border-ink-200 bg-white px-2 py-1.5 text-small tabular-nums
                              text-ink-900 focus:border-signal focus:outline-none">
                <x-button variant="secondary" size="sm" wire:click="shiftSelected">Shift days</x-button>
            </div>

            {{-- Retime --}}
            <div class="flex items-center gap-1">
                <input type="time" wire:model="bulkTime"
                       class="rounded-control border border-ink-200 bg-white px-2 py-1.5 text-small tabular-nums
                              text-ink-900 focus:border-signal focus:outline-none">
                <x-button variant="secondary" size="sm" wire:click="retimeSelected">Set time</x-button>
            </div>

            @can('approve-posts')
                <x-button variant="secondary" size="sm" wire:click="approveSelected">Approve</x-button>
            @endcan

            <x-button variant="secondary" size="sm" wire:click="duplicateSelected">
                Duplicate to next month
            </x-button>

            <x-button variant="danger" size="sm" wire:click="deleteSelected"
                      wire:confirm="Delete the selected posts? Anything already published is kept.">
                Delete
            </x-button>

            <button type="button" wire:click="$set('selected', [])"
                    class="ml-auto text-small font-semibold text-ink-500 hover:text-ink-900">Clear selection</button>

            @error('bulkTime')
                <p class="w-full text-small text-failed">{{ $message }}</p>
            @enderror
        </div>
    @endif

    {{-- ================================================================= --}}
    {{-- Table                                                             --}}
    {{-- ================================================================= --}}
    <x-card flush>
        <div class="overflow-x-auto">
            <table class="w-full text-left md:min-w-[52rem]">
                <thead class="bg-ink-050">
                    <tr>
                        <th class="w-10 px-3 py-2.5">
                            <input type="checkbox" wire:model.live="selectPage"
                                   aria-label="Select every post on this page"
                                   class="size-4 rounded border-ink-300 text-signal focus:ring-signal">
                        </th>
                        <th class="px-3 py-2.5 text-micro font-semibold uppercase text-ink-500">When</th>
                        <th class="px-3 py-2.5 text-micro font-semibold uppercase text-ink-500">Post</th>
                        <th class="hidden px-3 py-2.5 text-micro font-semibold uppercase text-ink-500 md:table-cell">Where</th>
                        <th class="px-3 py-2.5 text-micro font-semibold uppercase text-ink-500">Status</th>
                        <th class="hidden px-3 py-2.5 text-micro font-semibold uppercase text-ink-500 sm:table-cell">Author</th>
                        <th class="w-24 px-3 py-2.5"></th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($posts as $post)
                        @php $when = $post->scheduled_at?->copy()->setTimezone($tz); @endphp

                        <tr wire:key="post-{{ $post->id }}"
                            class="border-t border-ink-100 {{ in_array($post->id, $selected) ? 'bg-signal-weak' : 'hover:bg-ink-050' }}">

                            <td class="px-3 py-2.5">
                                <input type="checkbox" wire:model.live="selected" value="{{ $post->id }}"
                                       aria-label="Select this post"
                                       class="size-4 rounded border-ink-300 text-signal focus:ring-signal">
                            </td>

                            <td class="whitespace-nowrap px-3 py-2.5 text-small text-ink-700" data-numeric>
                                @if ($when)
                                    {{ $when->format('j M Y') }}
                                    <span class="block text-ink-500">{{ $when->format('H:i') }}</span>
                                @else
                                    <span class="text-ink-500">Not scheduled</span>
                                @endif
                            </td>

                            <td class="max-w-md px-3 py-2.5">
                                <a href="{{ route('posts.edit', ['post' => $post, 'from' => 'posts']) }}"
                                   class="block w-full truncate text-small font-medium text-ink-900 hover:text-signal"
                                   dir="auto">
                                    {{ $post->title ?: Str::limit(strip_tags((string) $post->caption), 70) }}
                                </a>
                                <span class="text-micro text-ink-500">
                                    {{ $post->type->glyph() }} {{ $post->type->label() }}
                                    @if ($post->wasPublishedLate())
                                        · <span class="text-pending">published late</span>
                                    @endif
                                </span>
                            </td>

                            <td class="hidden px-3 py-2.5 md:table-cell">
                                <span class="flex items-center gap-1">
                                    @foreach ($post->targets->map(fn ($t) => $t->socialAccount?->platform)->filter()->unique(fn ($p) => $p->value) as $platform)
                                        <x-platform-glyph :platform="$platform" class="size-3.5" />
                                    @endforeach
                                </span>
                            </td>

                            <td class="px-3 py-2.5">
                                <x-status-pill :status="$post->status" size="sm" />
                            </td>

                            <td class="hidden whitespace-nowrap px-3 py-2.5 text-small text-ink-500 sm:table-cell">
                                {{ $post->creator?->name ?? '—' }}
                            </td>

                            <td class="px-3 py-2.5 text-right">
                                {{-- Recycling a published post is two clicks:
                                     clone caption and media into a future slot. --}}
                                @if ($post->status->hasLeftTheBuilding())
                                    <button type="button" wire:click="recycle({{ $post->id }})"
                                            class="rounded-control px-2 py-1 text-micro font-semibold text-signal hover:bg-signal-weak">
                                        Recycle
                                    </button>
                                @else
                                    <a href="{{ route('posts.edit', ['post' => $post, 'from' => 'posts']) }}"
                                       class="inline-block rounded-control px-2 py-1 text-micro font-semibold text-ink-500 hover:bg-ink-050 hover:text-ink-900">
                                        Edit
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <x-empty-state
                                    title="{{ $hasFilters ? 'Nothing matches those filters' : 'No posts yet' }}"
                                    body="{{ $hasFilters ? 'Try clearing them.' : 'Write one in the composer, or import the calendar you already keep in a spreadsheet.' }}"
                                    icon="posts">
                                    <x-slot:actions>
                                        @if ($hasFilters)
                                            <x-button variant="secondary" wire:click="clearFilters">Clear filters</x-button>
                                        @else
                                            <x-button variant="primary" icon="plus" :href="route('posts.create', ['from' => 'posts'])">New post</x-button>
                                            <x-button variant="secondary" :href="route('import.index')">Import a spreadsheet</x-button>
                                        @endif
                                    </x-slot:actions>
                                </x-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    @if ($posts->hasPages())
        <div>{{ $posts->links() }}</div>
    @endif
</div>
