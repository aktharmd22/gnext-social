<div class="space-y-4">

    {{-- ================================================================= --}}
    {{-- Page picker, shown only just after a connect round trip           --}}
    {{-- ================================================================= --}}
    @if ($pending)
        <x-card title="Choose what to publish to"
                subtitle="These are the Pages that account manages. Connect only the ones this workspace posts to.">

            <div class="space-y-2">
                @foreach ($pending as $page)
                    <label class="flex cursor-pointer items-start gap-3 rounded-control border border-ink-200 p-3 hover:bg-ink-050">
                        <input type="checkbox" wire:model="selected" value="{{ $page['page_id'] }}"
                               class="mt-0.5 size-4 rounded border-ink-300 text-signal focus:ring-signal">

                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2">
                                <x-platform-glyph :platform="\App\Enums\Platform::Facebook" class="size-4" />
                                <span class="truncate text-body font-semibold text-ink-900">{{ $page['name'] }}</span>
                            </span>

                            @if (! empty($page['instagram']))
                                <span class="mt-1 flex items-center gap-2 text-small text-ink-500">
                                    <x-platform-glyph :platform="\App\Enums\Platform::Instagram" class="size-3.5" />
                                    Instagram
                                    @if (! empty($page['instagram']['username']))
                                        <span class="font-mono">&#64;{{ $page['instagram']['username'] }}</span>
                                    @endif
                                    is linked and will be connected too
                                </span>
                            @else
                                <span class="mt-1 block text-small text-ink-500">
                                    No Instagram Business account is linked to this Page, so only Facebook can be published to.
                                </span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-ink-100 pt-4">
                <x-button type="button" variant="primary" wire:click="connectSelected">
                    Connect selected
                </x-button>
                <x-button type="button" variant="ghost" wire:click="discardPending">
                    Cancel
                </x-button>
            </div>
        </x-card>
    @endif

    {{-- ================================================================= --}}
    {{-- Connected destinations                                            --}}
    {{-- ================================================================= --}}
    <x-card title="Connected accounts"
            subtitle="Every destination this workspace can publish to.">

        <x-slot:actions>
            @if ($hasCredential)
                <x-button variant="primary" size="sm" :href="route('oauth.facebook.redirect')">
                    Connect Facebook
                </x-button>
            @else
                <x-button variant="secondary" size="sm" :href="route('settings.meta-app')">
                    Add credentials first
                </x-button>
            @endif
        </x-slot:actions>

        @forelse ($accounts as $account)
            @php
                $days = $account->tokenExpiresInDays();
                $expired = $account->tokenHasExpired();
                $warning = $account->tokenNeedsAttention();
            @endphp

            <div @class([
                'flex flex-wrap items-center gap-3 border-b border-ink-100 py-3 first:pt-0 last:border-0 last:pb-0',
            ])>
                <x-platform-glyph :platform="$account->platform" class="size-5 shrink-0" />

                <div class="min-w-0 flex-1">
                    <p class="truncate text-body font-semibold text-ink-900">{{ $account->name }}</p>
                    <p class="truncate text-small text-ink-500">
                        {{ $account->platform->label() }}
                        @if ($account->username)
                            · <span class="font-mono">&#64;{{ $account->username }}</span>
                        @endif
                    </p>
                </div>

                {{-- Token health. Never the token. --}}
                <div class="shrink-0 text-right">
                    @if (! $account->is_active)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-cancelled-soft px-2 py-[3px] text-small font-semibold text-cancelled">
                            <span aria-hidden="true">⊘</span> Disconnected
                        </span>
                        <p class="mt-1 text-small text-ink-500">
                            History kept. Reconnect to publish here again.
                        </p>
                    @elseif ($expired)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-failed-soft px-2 py-[3px] text-small font-semibold text-failed">
                            <span aria-hidden="true">✕</span> Token expired
                        </span>
                        <p class="mt-1 text-small text-ink-500">Reconnect to resume publishing.</p>
                    @elseif ($warning)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-pending-soft px-2 py-[3px] text-small font-semibold text-pending">
                            <span aria-hidden="true">◐</span>
                            {{ $days <= 0 ? 'Expires today' : 'Expires in '.$days.' '.Str::plural('day', $days) }}
                        </span>
                        <p class="mt-1 text-small text-ink-500">Reconnect before it lapses.</p>
                    @elseif ($account->token_expires_at)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-published-soft px-2 py-[3px] text-small font-semibold text-published">
                            <span aria-hidden="true">●</span> Healthy
                        </span>
                        <p class="mt-1 text-small text-ink-500" data-numeric>
                            Until {{ $account->token_expires_at->timezone(auth()->user()->displayTimezone())->format('j M Y') }}
                        </p>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-draft-soft px-2 py-[3px] text-small font-semibold text-draft">
                            <span aria-hidden="true">○</span> No expiry recorded
                        </span>
                    @endif
                </div>

                @if ($confirmingDisconnect === $account->id)
                    <div class="flex w-full items-center justify-end gap-2 rounded-control bg-failed-soft px-3 py-2">
                        <span class="mr-auto text-small text-failed">
                            Disconnect {{ $account->displayName() }}? Anything queued to it will be marked skipped.
                        </span>
                        <x-button variant="danger" size="sm" wire:click="disconnect({{ $account->id }})">
                            Disconnect
                        </x-button>
                        <x-button variant="ghost" size="sm" wire:click="cancelDisconnect">Keep</x-button>
                    </div>
                @elseif ($account->is_active)
                    <button type="button" wire:click="confirmDisconnect({{ $account->id }})"
                            class="shrink-0 rounded-control px-2.5 py-1.5 text-small font-semibold text-ink-500 hover:bg-ink-050 hover:text-failed">
                        Disconnect
                    </button>
                @endif
            </div>
        @empty
            <x-empty-state
                title="No accounts connected"
                body="Connect Facebook, choose which Pages to publish to, and any linked Instagram Business account comes with it."
                icon="settings">
                <x-slot:actions>
                    @if ($hasCredential)
                        <x-button variant="primary" :href="route('oauth.facebook.redirect')">Connect Facebook</x-button>
                    @else
                        <x-button variant="primary" :href="route('settings.meta-app')">Add Meta credentials</x-button>
                    @endif
                </x-slot:actions>
            </x-empty-state>
        @endforelse
    </x-card>
</div>
