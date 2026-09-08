@php
    use App\Enums\Platform;
@endphp

<div class="space-y-4">
    @forelse ($posts as $post)
        @php
            $when = $post->scheduled_at?->copy()->setTimezone($tz);
            $overdue = $when !== null && $when->isPast();

            $facebook = $post->targets->first(fn ($t) => $t->socialAccount?->platform === Platform::Facebook)?->socialAccount;
            $instagram = $post->targets->first(fn ($t) => $t->socialAccount?->platform === Platform::Instagram)?->socialAccount;

            $clientActions = $post->reviewActions;
        @endphp

        <x-card wire:key="approval-{{ $post->id }}">
            <div class="grid min-w-0 gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">

                {{-- ------------------------------------------------- detail --}}
                <div class="min-w-0 space-y-3">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h2 class="truncate text-h2">
                                {{ $post->title ?: Str::limit(strip_tags((string) $post->caption), 60) }}
                            </h2>
                            <p class="mt-0.5 text-small text-ink-500">
                                {{ $post->type->label() }} ·
                                by {{ $post->creator?->name ?? 'someone since removed' }}
                                @if ($when)
                                    · <time data-numeric>{{ $when->format('D j M, H:i') }}</time> {{ \App\Support\Zone::label($tz) }}
                                @endif
                            </p>
                        </div>

                        <x-status-pill :status="$post->status" size="sm" />
                    </div>

                    {{-- A post still waiting past its own slot has already
                         missed it. Silence here would be the failure. --}}
                    @if ($overdue)
                        <p class="flex items-start gap-2 rounded-control border border-failed/25 bg-failed-soft px-3 py-2 text-small text-failed">
                            <span aria-hidden="true">✕</span>
                            <span>This was due {{ $when->diffForHumans() }} and is still waiting. It will not publish until approved.</span>
                        </p>
                    @endif

                    <p class="whitespace-pre-line break-words rounded-control border border-ink-100 bg-ink-050 p-3 text-small leading-snug text-ink-700" dir="auto">{{ $post->caption }}</p>

                    @if (filled($post->caption_ar))
                        <p class="whitespace-pre-line break-words rounded-control border border-ink-100 bg-ink-050 p-3 text-small leading-relaxed text-ink-700" dir="rtl" lang="ar">{{ $post->caption_ar }}</p>
                    @endif

                    <div class="flex flex-wrap items-center gap-3">
                        @foreach ($post->media as $item)
                            <div class="size-16 overflow-hidden rounded-chip border border-ink-100 bg-ink-050">
                                @if ($item->thumbnailUrl())
                                    <img src="{{ $item->thumbnailUrl() }}" alt="" class="size-full object-cover">
                                @else
                                    <div class="flex size-full items-center justify-center text-ink-300">
                                        <x-icon name="media" class="size-5" />
                                    </div>
                                @endif
                            </div>
                        @endforeach

                        <span class="flex items-center gap-1.5 text-small text-ink-500">
                            @foreach ($post->targets as $target)
                                @if ($target->socialAccount)
                                    <x-platform-glyph :platform="$target->socialAccount->platform" class="size-4" />
                                @endif
                            @endforeach
                            {{ $post->targets->count() }} {{ Str::plural('destination', $post->targets->count()) }}
                        </span>
                    </div>

                    {{-- What the client already said, if the link was shared. --}}
                    @if ($clientActions->isNotEmpty())
                        <div class="rounded-control border border-ink-100 p-3">
                            <p class="text-micro font-semibold uppercase text-ink-500">From the review link</p>

                            @foreach ($clientActions as $action)
                                <p class="mt-1.5 text-small text-ink-700">
                                    <span class="font-semibold" style="color: var(--color-{{ $action->action === 'approve' ? 'published' : 'pending' }});">
                                        {{ $action->action === 'approve' ? '● Approved' : '◐ Comment' }}
                                    </span>
                                    <span class="text-ink-500" data-numeric>{{ $action->created_at?->diffForHumans() }}</span>
                                    @if ($action->note)
                                        <span class="mt-0.5 block" dir="auto">“{{ $action->note }}”</span>
                                    @endif
                                </p>
                            @endforeach
                        </div>
                    @endif

                    {{-- ------------------------------------------- decisions --}}
                    @if ($decidingOn === $post->id)
                        <div class="space-y-2 rounded-control border border-pending/25 bg-pending-soft p-3">
                            <label for="note-{{ $post->id }}" class="block text-small font-semibold text-pending">
                                What needs changing?
                            </label>

                            <textarea id="note-{{ $post->id }}" wire:model="note" rows="3" dir="auto"
                                      placeholder="Swap the hero image for the wide crop, and drop the third hashtag."
                                      class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                             text-ink-900 focus:border-signal focus:outline-none"></textarea>

                            @error('note')
                                <p class="text-small text-failed">{{ $message }}</p>
                            @enderror

                            <div class="flex flex-wrap gap-2">
                                <x-button variant="primary" size="sm" wire:click="requestChanges">Send it back</x-button>
                                <x-button variant="ghost" size="sm" wire:click="cancelRequestingChanges">Cancel</x-button>
                            </div>
                        </div>
                    @else
                        <div class="flex flex-wrap items-center gap-2 border-t border-ink-100 pt-3">
                            <x-button variant="primary" wire:click="approve({{ $post->id }})">Approve</x-button>

                            <x-button variant="secondary" wire:click="startRequestingChanges({{ $post->id }})">
                                Request changes
                            </x-button>

                            <button type="button" wire:click="shareLink({{ $post->id }})"
                                    class="rounded-control px-2.5 py-2 text-small font-semibold text-ink-500 hover:bg-ink-050 hover:text-ink-900">
                                Get a client link
                            </button>
                        </div>

                        @if (isset($shareLinks[$post->id]))
                            <div class="space-y-1.5" x-data="{ copied: false }">
                                <div class="flex gap-2">
                                    <input type="text" readonly value="{{ $shareLinks[$post->id] }}"
                                           x-ref="link"
                                           class="w-full rounded-control border border-ink-200 bg-ink-050 px-3 py-2 text-small text-ink-700">
                                    <button type="button"
                                            x-on:click="navigator.clipboard.writeText($refs.link.value); copied = true; setTimeout(() => copied = false, 1800)"
                                            class="shrink-0 rounded-control border border-ink-200 bg-white px-3 py-2 text-small font-semibold text-ink-700 hover:bg-ink-050">
                                        <span x-show="!copied">Copy</span>
                                        <span x-show="copied" x-cloak class="text-published">Copied</span>
                                    </button>
                                </div>
                                <p class="text-small text-ink-500">
                                    Read-only, expires in {{ config('gnext.review.link_ttl_days') }} days,
                                    and needs no account. A client approval here is a signal — someone on the team still confirms it.
                                </p>
                            </div>
                        @endif
                    @endif
                </div>

                {{-- ------------------------------------------------ previews --}}
                <div class="space-y-3">
                    <p class="text-micro font-semibold uppercase text-ink-500">As it will appear</p>

                    <x-preview.instagram
                        :caption="$post->caption"
                        :account="$instagram"
                        :media="$post->media->first()"
                        :type="$post->type->value"
                        :first-comment="$post->first_comment"
                        :when="$when?->format('j M, H:i')" />

                    <x-preview.facebook
                        :caption="$post->caption"
                        :account="$facebook"
                        :media="$post->media->first()"
                        :type="$post->type->value"
                        :when="$when?->format('j M, H:i')" />
                </div>
            </div>
        </x-card>
    @empty
        <x-card flush>
            <x-empty-state
                title="Nothing waiting"
                body="When someone submits a post for approval it appears here, with its media and both platform previews."
                icon="approvals" />
        </x-card>
    @endforelse
</div>
