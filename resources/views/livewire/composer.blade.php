@php
    use App\Enums\Platform;
    use App\Enums\PostType;

    $counters = $this->counters;
    $verdicts = $this->verdicts;
    $duplicate = $this->duplicate;

    $facebookAccount = $accounts->firstWhere(fn ($a) => $a->platform === Platform::Facebook
        && in_array($a->id, $destinations, true));
    $instagramAccount = $accounts->firstWhere(fn ($a) => $a->platform === Platform::Instagram
        && in_array($a->id, $destinations, true));

    $firstMedia = $media->first();

    // The previews show the scheduled moment where the platform shows a
    // timestamp, so the mock reads as the post it will become.
    $previewWhen = $scheduledDate
        ? \Illuminate\Support\Carbon::parse($scheduledDate.' '.($scheduledTime ?: '00:00'))->format('j M, H:i')
        : null;
@endphp

{{--
    The composer is a page, not a modal.

    A post therefore has a URL: it can be linked in chat, opened in its own tab
    from the calendar, bookmarked, and left with the browser's back button. A
    drawer can do none of that, and a month of content is reviewed by opening
    many posts, not one.
--}}
<div>
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-display">{{ $postId ? 'Edit post' : 'New post' }}</h1>
            <p class="mt-0.5 text-small text-ink-500">
                @if ($postId)
                    Post <span data-numeric>#{{ $postId }}</span> ·
                @endif
                Times are in <span data-numeric>{{ $timezoneLabel }}</span>.
            </p>
        </div>

        <x-button variant="secondary" wire:click="close" icon="chevron-left">
            Back
        </x-button>
    </div>

    <div class="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1fr)_26rem]">

        {{-- =============================================================== --}}
        {{-- Form                                                            --}}
        {{-- =============================================================== --}}
        <x-card>
            <div class="space-y-5">

                {{-- Internal title --}}
                <x-field label="Title" name="title" hint="Internal only. Never published.">
                    <x-slot:control>
                        <input id="title" type="text" wire:model="title"
                               placeholder="Back-to-school range"
                               class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-body
                                      text-ink-900 placeholder:text-ink-500 focus:border-signal focus:outline-none">
                    </x-slot:control>
                </x-field>

                {{-- Type --}}
                <div class="space-y-1.5">
                    <span class="block text-small font-semibold text-ink-900">Type</span>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach (PostType::cases() as $postType)
                            <button type="button" wire:click="$set('type', '{{ $postType->value }}')"
                                    @class([
                                        'inline-flex items-center gap-1.5 rounded-control border px-3 py-1.5 text-small font-semibold transition-colors',
                                        'border-signal bg-signal-weak text-signal' => $type === $postType->value,
                                        'border-ink-200 bg-white text-ink-700 hover:bg-ink-050' => $type !== $postType->value,
                                    ])>
                                <span aria-hidden="true">{{ $postType->glyph() }}</span>
                                {{ $postType->label() }}
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Destinations --}}
                <div class="space-y-1.5">
                    <span class="block text-small font-semibold text-ink-900">
                        Publish to <span class="ml-1 font-normal text-failed">required</span>
                    </span>

                    @forelse ($accounts as $account)
                        <label class="flex cursor-pointer items-center gap-2.5 rounded-control border border-ink-200 px-3 py-2 hover:bg-ink-050">
                            <input type="checkbox" wire:model.live="destinations" value="{{ $account->id }}"
                                   class="size-4 rounded border-ink-300 text-signal focus:ring-signal">
                            <x-platform-glyph :platform="$account->platform" class="size-4" />
                            <span class="min-w-0 flex-1 truncate text-small text-ink-900">{{ $account->name }}</span>
                            <span class="text-micro font-semibold text-ink-500">{{ $account->platform->shortLabel() }}</span>
                        </label>
                    @empty
                        <p class="rounded-control border border-dashed border-ink-200 px-3 py-3 text-small text-ink-500">
                            No accounts connected yet.
                            <a href="{{ route('settings.accounts') }}" class="font-semibold text-signal">Connect one first.</a>
                        </p>
                    @endforelse

                    @error('destinations')
                        <p class="text-small text-failed">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Caption --}}
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between gap-2">
                        <label for="caption" class="text-small font-semibold text-ink-900">
                            Caption <span class="ml-1 font-normal text-failed">required</span>
                        </label>

                        <button type="button" wire:click="$toggle('splitCaptions')"
                                class="text-small font-semibold {{ $splitCaptions ? 'text-signal' : 'text-ink-500 hover:text-ink-900' }}">
                            {{ $splitCaptions ? 'Using one caption per platform' : 'Split per platform' }}
                        </button>
                    </div>

                    <textarea id="caption" wire:model.live.debounce.400ms="caption" rows="6" dir="auto"
                              placeholder="Write the post…"
                              class="w-full rounded-control border bg-white px-3 py-2 text-body leading-snug text-ink-900
                                     placeholder:text-ink-500 focus:outline-none
                                     {{ $errors->has('caption') ? 'border-failed' : 'border-ink-200 focus:border-signal' }}"></textarea>

                    @error('caption')
                        <p class="text-small text-failed">{{ $message }}</p>
                    @enderror

                    {{-- Live counters, one row per destination platform. --}}
                    <div class="flex flex-wrap gap-x-4 gap-y-1">
                        @foreach ($counters as $platformValue => $counter)
                            @php
                                $tone = match ($counter['state']) {
                                    'over' => 'text-failed',
                                    'near' => 'text-pending',
                                    default => 'text-ink-500',
                                };
                            @endphp

                            <span class="flex items-center gap-2 text-micro font-semibold {{ $tone }}">
                                <x-platform-glyph :platform="\App\Enums\Platform::from($platformValue)" class="size-3" />
                                <span data-numeric>{{ number_format($counter['length']) }}/{{ number_format($counter['limit']) }}</span>
                                @if ($counter['hashtagLimit'])
                                    <span data-numeric>· {{ $counter['hashtags'] }}/{{ $counter['hashtagLimit'] }} tags</span>
                                @endif
                                @if ($counter['mentionLimit'])
                                    <span data-numeric>· {{ $counter['mentions'] }}/{{ $counter['mentionLimit'] }} &#64;</span>
                                @endif
                            </span>
                        @endforeach
                    </div>
                </div>

                {{-- Per-platform overrides --}}
                @if ($splitCaptions)
                    <div class="space-y-3 rounded-card border border-ink-100 bg-ink-050 p-3">
                        <p class="text-small text-ink-500">
                            Leave one blank to fall back to the caption above.
                            Typically Facebook runs clean prose and Instagram carries the hashtags.
                        </p>

                        <div class="space-y-1.5">
                            <label for="captionFacebook" class="flex items-center gap-2 text-small font-semibold text-ink-900">
                                <x-platform-glyph :platform="\App\Enums\Platform::Facebook" class="size-3.5" /> Facebook
                            </label>
                            <textarea id="captionFacebook" wire:model.live.debounce.400ms="captionFacebook" rows="3" dir="auto"
                                      class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                             text-ink-900 focus:border-signal focus:outline-none"></textarea>
                        </div>

                        <div class="space-y-1.5">
                            <label for="captionInstagram" class="flex items-center gap-2 text-small font-semibold text-ink-900">
                                <x-platform-glyph :platform="\App\Enums\Platform::Instagram" class="size-3.5" /> Instagram
                            </label>
                            <textarea id="captionInstagram" wire:model.live.debounce.400ms="captionInstagram" rows="3" dir="auto"
                                      class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                             text-ink-900 focus:border-signal focus:outline-none"></textarea>
                        </div>
                    </div>
                @endif

                {{-- Arabic --}}
                <div class="space-y-1.5">
                    <button type="button" wire:click="$toggle('showArabic')"
                            class="text-small font-semibold {{ $showArabic ? 'text-signal' : 'text-ink-500 hover:text-ink-900' }}">
                        {{ $showArabic ? 'Hide Arabic caption' : 'Add an Arabic caption' }}
                    </button>

                    @if ($showArabic)
                        <textarea id="captionAr" wire:model.live.debounce.400ms="captionAr" rows="4"
                                  dir="rtl" lang="ar" placeholder="اكتب التسمية التوضيحية هنا…"
                                  class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-body
                                         leading-relaxed text-ink-900 focus:border-signal focus:outline-none"></textarea>
                    @endif
                </div>

                {{-- Templates and hashtag sets --}}
                @if ($templates->isNotEmpty() || $hashtagSets->isNotEmpty())
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($templates as $template)
                            <button type="button" wire:click="insertTemplate({{ $template->id }})"
                                    class="rounded-full border border-ink-200 bg-white px-2.5 py-1 text-micro font-semibold
                                           text-ink-700 hover:bg-ink-050 hover:text-ink-900">
                                + {{ $template->name }}
                            </button>
                        @endforeach

                        @foreach ($hashtagSets as $set)
                            <button type="button" wire:click="insertHashtagSet({{ $set->id }})"
                                    class="rounded-full border border-ink-200 bg-white px-2.5 py-1 text-micro font-semibold
                                           text-ink-700 hover:bg-ink-050 hover:text-ink-900">
                                # {{ $set->name }}
                            </button>
                        @endforeach
                    </div>
                @endif

                {{-- Duplicate warning --}}
                @if ($duplicate)
                    <div class="rounded-control border border-pending/25 bg-pending-soft p-3">
                        <p class="text-small font-semibold text-pending">
                            {{ $duplicate['similarity'] }}% similar to something you already published
                        </p>
                        <p class="mt-1 text-small text-ink-700">
                            “{{ Str::limit($duplicate['post']->title ?: $duplicate['post']->caption, 60) }}”,
                            published {{ $duplicate['post']->published_at?->diffForHumans() }}.
                            Recycling on purpose is fine — this is only a heads-up.
                        </p>
                    </div>
                @endif

                {{-- Media --}}
                <div class="space-y-2">
                    <span class="block text-small font-semibold text-ink-900">Media</span>

                    @foreach ($media as $item)
                        <div class="flex items-start gap-3 rounded-control border border-ink-200 p-2.5">
                            <div class="size-14 shrink-0 overflow-hidden rounded-chip bg-ink-050">
                                @if ($item->thumbnailUrl())
                                    <img src="{{ $item->thumbnailUrl() }}" alt="" class="size-full object-cover">
                                @else
                                    <div class="flex size-full items-center justify-center text-ink-300">
                                        <x-icon name="media" class="size-4" />
                                    </div>
                                @endif
                            </div>

                            <div class="min-w-0 flex-1">
                                <p class="flex items-center gap-2 text-small font-semibold"
                                   style="color: var(--color-{{ $item->status->token() }});">
                                    <span aria-hidden="true">{{ $item->status->isReady() ? '●' : ($item->status->value === 'failed' ? '✕' : '◍') }}</span>
                                    {{ $item->status->label() }}
                                </p>

                                @if ($item->status->isReady())
                                    <p class="mt-0.5 text-small text-ink-500" data-numeric>
                                        {{ $item->width }}×{{ $item->height }}
                                        @if ($item->aspectLabel()) · {{ $item->aspectLabel() }} @endif
                                        @if ($item->humanSize()) · {{ $item->humanSize() }} @endif
                                    </p>
                                @elseif ($item->error_message)
                                    <p class="mt-0.5 break-words text-small text-failed">{{ $item->error_message }}</p>
                                @else
                                    <p class="mt-0.5 truncate text-small text-ink-500">{{ $item->source_url }}</p>
                                @endif
                            </div>

                            <div class="flex shrink-0 flex-col items-end gap-1">
                                @if (! $item->status->isReady())
                                    <button type="button" wire:click="refetchMedia({{ $item->id }})"
                                            class="rounded-control px-2 py-1 text-micro font-semibold text-signal hover:bg-signal-weak">
                                        Retry
                                    </button>
                                @else
                                    {{-- Offer the fix, not just the verdict. --}}
                                    <span class="flex gap-1">
                                        <button type="button" wire:click="refit({{ $item->id }}, 'crop')"
                                                wire:loading.attr="disabled" wire:target="refit"
                                                class="rounded-control px-2 py-1 text-micro font-semibold text-signal hover:bg-signal-weak">
                                            Crop
                                        </button>
                                        <button type="button" wire:click="refit({{ $item->id }}, 'pad')"
                                                wire:loading.attr="disabled" wire:target="refit"
                                                class="rounded-control px-2 py-1 text-micro font-semibold text-signal hover:bg-signal-weak">
                                            Pad
                                        </button>
                                    </span>
                                @endif

                                <button type="button" wire:click="removeMedia({{ $item->id }})"
                                        class="rounded-control px-2 py-1 text-micro font-semibold text-ink-500 hover:bg-ink-050 hover:text-failed">
                                    Remove
                                </button>
                            </div>
                        </div>
                    @endforeach

                    {{-- Upload, for the common case of a file on the
                         machine you are sitting at. --}}
                    <label class="block cursor-pointer rounded-control border-2 border-dashed border-ink-200 px-3 py-4 text-center hover:border-signal hover:bg-signal-weak">
                        <input type="file" wire:model="upload" class="sr-only"
                               accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime">
                        <span class="text-small font-semibold text-ink-700">Choose a file</span>
                        <span class="block text-micro text-ink-500">JPEG, PNG, WebP, GIF, MP4 or MOV — up to 500MB</span>
                        <span wire:loading wire:target="upload" class="mt-1 block text-micro font-semibold text-signal">
                            Uploading…
                        </span>
                    </label>

                    @error('upload')
                        <p class="text-small text-failed">{{ $message }}</p>
                    @enderror

                    <div class="flex items-center gap-2">
                        <span class="h-px flex-1 bg-ink-100"></span>
                        <span class="text-micro text-ink-500">or paste a link</span>
                        <span class="h-px flex-1 bg-ink-100"></span>
                    </div>

                    <div class="flex gap-2">
                        <input type="url" wire:model="mediaUrl" wire:keydown.enter.prevent="addMedia"
                               placeholder="Paste a Google Drive link or any public URL"
                               class="w-full rounded-control border bg-white px-3 py-2 text-small text-ink-900
                                      placeholder:text-ink-500 focus:outline-none
                                      {{ $errors->has('mediaUrl') ? 'border-failed' : 'border-ink-200 focus:border-signal' }}">
                        <x-button type="button" variant="secondary" wire:click="addMedia" class="shrink-0">Add</x-button>
                    </div>

                    @error('mediaUrl')
                        <p class="text-small text-failed">{{ $message }}</p>
                    @enderror

                    @error('media')
                        <p class="text-small text-failed">{{ $message }}</p>
                    @enderror

                    <p class="text-small text-ink-500">
                        Drive links are downloaded here and re-served from this server, because Meta
                        fetches media itself and cannot open a Drive viewer page.
                    </p>
                </div>

                {{-- Platform verdicts --}}
                @foreach ($verdicts as $platformValue => $found)
                    @if ($found)
                        <div class="space-y-1.5">
                            @foreach ($found as $verdict)
                                <div @class([
                                    'flex items-start gap-2 rounded-control border p-2.5 text-small',
                                    'border-failed/25 bg-failed-soft text-failed' => $verdict->isError(),
                                    'border-pending/25 bg-pending-soft text-pending' => ! $verdict->isError(),
                                ])>
                                    <span aria-hidden="true" class="mt-px">{{ $verdict->isError() ? '✕' : '◐' }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="font-semibold">{{ Str::upper($platformValue === 'instagram' ? 'IG' : 'FB') }}</span>
                                        {{ $verdict->message }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endforeach

                {{-- Schedule --}}
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-field label="Date" name="scheduledDate" required>
                        <x-slot:control>
                            <input id="scheduledDate" type="date" wire:model.live="scheduledDate"
                                   class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-body
                                          text-ink-900 tabular-nums focus:border-signal focus:outline-none">
                        </x-slot:control>
                    </x-field>

                    <x-field label="Time" name="scheduledTime" required
                             hint="Shown in {{ $timezoneLabel }}.">
                        <x-slot:control>
                            <input id="scheduledTime" type="time" wire:model.live="scheduledTime"
                                   class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-body
                                          text-ink-900 tabular-nums focus:border-signal focus:outline-none">
                        </x-slot:control>
                    </x-field>
                </div>

                {{-- Suggested times, from measured engagement. --}}
                @if ($suggestedSlots->isNotEmpty())
                    <div class="space-y-1.5">
                        <span class="block text-micro font-semibold uppercase text-ink-500">
                            Your best slots so far
                        </span>

                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($suggestedSlots as $slot)
                                <button type="button"
                                        wire:click="useSuggestedSlot({{ $slot['weekday'] }}, {{ $slot['hour'] }})"
                                        class="rounded-full border border-ink-200 bg-white px-2.5 py-1 text-micro
                                               font-semibold text-ink-700 hover:border-signal hover:text-signal">
                                    {{ $slot['label'] }}
                                    <span class="text-published" data-numeric>{{ $slot['rate'] }}%</span>
                                </button>
                            @endforeach
                        </div>

                        <p class="text-small text-ink-500">
                            From your own published history. A hint, not a rule.
                        </p>
                    </div>
                @endif

                {{-- First comment + footer --}}
                <x-field label="First comment" name="firstComment"
                         hint="Posted immediately after publishing. Often where the hashtags go.">
                    <x-slot:control>
                        <textarea id="firstComment" wire:model="firstComment" rows="2" dir="auto"
                                  class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                         text-ink-900 focus:border-signal focus:outline-none"></textarea>
                    </x-slot:control>
                </x-field>

                <label class="flex items-start gap-2.5">
                    <input type="checkbox" wire:model.live="appendBrandFooter"
                           class="mt-0.5 size-4 rounded border-ink-300 text-signal focus:ring-signal">
                    <span class="text-small text-ink-700">
                        Append the brand footer
                        <span class="block text-ink-500">
                            Added when the post publishes, so changing it once changes it everywhere.
                        </span>
                    </span>
                </label>
                    </div>
        </x-card>

        {{-- =============================================================== --}}
        {{-- Live preview. Sticky, so it stays beside the caption as the form --}}
        {{-- scrolls -- the point of a preview is watching it change.        --}}
        {{-- =============================================================== --}}
        <div class="min-w-0">
            <div class="xl:sticky xl:top-4">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <p class="text-micro font-semibold uppercase text-ink-500">Preview</p>
                    <p class="truncate text-micro text-ink-500">Truncation shown per platform</p>
                </div>

                {{--
                    Both platforms at once rather than a tab switch: the whole
                    reason per-platform captions exist is seeing that the same
                    words break differently, and a tab hides exactly that.
                --}}
                <div class="space-y-4">
                    @foreach ([
                        ['platform' => \App\Enums\Platform::Instagram, 'account' => $instagramAccount],
                        ['platform' => \App\Enums\Platform::Facebook, 'account' => $facebookAccount],
                    ] as $pane)
                        <div>
                            <div class="mb-1.5 flex items-baseline justify-between gap-2">
                                <span class="flex min-w-0 items-center gap-1.5 text-small font-semibold text-ink-700">
                                    <x-platform-glyph :platform="$pane['platform']" class="size-3.5 shrink-0" />
                                    <span class="truncate">{{ $pane['platform']->label() }}</span>
                                </span>

                                @if (! $pane['account'])
                                    <span class="shrink-0 text-micro text-ink-500">No account connected</span>
                                @endif
                            </div>

                            @if ($pane['platform'] === \App\Enums\Platform::Instagram)
                                <x-preview.instagram
                                    :caption="$this->effectiveCaption(\App\Enums\Platform::Instagram)"
                                    :account="$instagramAccount"
                                    :media="$firstMedia"
                                    :type="$type"
                                    :first-comment="$firstComment"
                                    :when="$previewWhen" />
                            @else
                                <x-preview.facebook
                                    :caption="$this->effectiveCaption(\App\Enums\Platform::Facebook)"
                                    :account="$facebookAccount"
                                    :media="$firstMedia"
                                    :type="$type"
                                    :when="$previewWhen" />
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>


    {{-- =================================================================== --}}
    {{-- Actions. Sticky to the bottom of the viewport so they are reachable  --}}
    {{-- without scrolling back down a long form.                            --}}
    {{-- =================================================================== --}}
    <div class="sticky bottom-0 z-20 -mx-4 mt-4 border-t border-ink-100 bg-white px-4 py-3 xl:-mx-6 xl:px-6">
        @error('blocking')
            <p class="mb-2 text-small text-failed">{{ $message }}</p>
        @enderror

        <div class="flex flex-wrap items-center gap-2">
            <x-button type="button" variant="primary" wire:click="schedule">
                {{ auth()->user()->isAdmin() ? 'Schedule post' : 'Submit for approval' }}
            </x-button>

            <x-button type="button" variant="secondary" wire:click="saveDraft">Save draft</x-button>

            <x-button type="button" variant="ghost" wire:click="close">Cancel</x-button>

            @if ($postId)
                <x-button type="button" variant="danger" size="sm" wire:click="deletePost"
                          wire:confirm="Delete this post? This cannot be undone."
                          class="ml-auto">
                    Delete
                </x-button>
            @endif
        </div>
    </div>
</div>
