<div class="space-y-4">

    <div class="flex flex-wrap items-center gap-2">
        <select wire:model.live="status"
                class="rounded-control border border-ink-200 bg-white px-3 py-2 text-small text-ink-900 focus:border-signal focus:outline-none">
            <option value="">Any state</option>
            @foreach ($statusOptions as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </select>

        @if ($failedCount > 0)
            <x-button variant="secondary" wire:click="refetchAllFailed">
                Retry all {{ $failedCount }} failed
            </x-button>
        @endif
    </div>

    <x-card flush>
        <div class="divide-y divide-ink-100">
            @forelse ($media as $item)
                <div wire:key="media-{{ $item->id }}" class="flex items-start gap-3 p-3">
                    <div class="size-14 shrink-0 overflow-hidden rounded-chip bg-ink-050">
                        @if ($item->thumbnailUrl())
                            <img src="{{ $item->thumbnailUrl() }}" alt="" class="size-full object-cover">
                        @else
                            <div class="flex size-full items-center justify-center text-ink-300">
                                <x-icon name="media" class="size-5" />
                            </div>
                        @endif
                    </div>

                    <div class="min-w-0 flex-1">
                        <a href="{{ route('posts.edit', ['post' => $item->post_id, 'from' => 'media']) }}"
                           class="block max-w-full truncate text-small font-semibold text-ink-900 hover:text-signal"
                           dir="auto">
                            {{ $item->post?->title ?: Str::limit(strip_tags((string) $item->post?->caption), 60) }}
                        </a>

                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-small"
                           style="color: var(--color-{{ $item->status->token() }});">
                            <span aria-hidden="true">{{ $item->status->isReady() ? '●' : ($item->status->value === 'failed' ? '✕' : '◍') }}</span>
                            <span>{{ $item->status->label() }}</span>

                            @if ($item->status->isReady())
                                <span class="text-ink-500" data-numeric>
                                    {{ $item->width }}×{{ $item->height }}
                                    @if ($item->aspectLabel()) · {{ $item->aspectLabel() }} @endif
                                    @if ($item->humanSize()) · {{ $item->humanSize() }} @endif
                                    · {{ $item->source_type->label() }}
                                </span>
                            @endif
                        </p>

                        @if ($item->error_message)
                            <p class="mt-0.5 break-words text-small text-failed">{{ $item->error_message }}</p>
                        @elseif (! $item->status->isReady())
                            <p class="mt-0.5 truncate text-small text-ink-500">{{ $item->source_url }}</p>
                        @endif
                    </div>

                    @unless ($item->status->isReady())
                        <button type="button" wire:click="refetch({{ $item->id }})"
                                class="shrink-0 rounded-control px-2.5 py-1.5 text-micro font-semibold text-signal hover:bg-signal-weak">
                            Fetch again
                        </button>
                    @endunless
                </div>
            @empty
                <x-empty-state
                    title="No media yet"
                    body="Drop a file, paste a Google Drive link, or point at any public URL. We fetch the bytes and hand Meta a URL it can actually read."
                    icon="media" />
            @endforelse
        </div>
    </x-card>

    @if ($media->hasPages())
        <div>{{ $media->links() }}</div>
    @endif
</div>
