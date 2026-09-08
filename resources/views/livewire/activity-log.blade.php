@php
    /**
     * Action names are stored as machine strings so they can be filtered and
     * grouped. This turns them into something a human reads without losing that.
     */
    $describe = function (string $action): string {
        return match ($action) {
            'post.created' => 'created a post',
            'post.updated' => 'edited a post',
            'post.approved' => 'approved a post',
            'post.changes_requested' => 'requested changes',
            'post.rescheduled' => 'moved a post',
            'meta_app.updated' => 'changed the Meta app settings',
            'meta.oauth.completed' => 'connected with Facebook',
            'account.connected' => 'connected an account',
            'account.disconnected' => 'disconnected an account',
            'import.completed' => 'ran an import',
            'import.undone' => 'undid an import',
            'export.posts' => 'exported posts',
            'export.calendar-pdf' => 'exported a calendar PDF',
            'review.approved' => 'approved via the client link',
            'review.commented' => 'commented via the client link',
            default => str_replace(['.', '_'], [' ', ' '], $action),
        };
    };
@endphp

<div class="space-y-4">

    <div class="flex flex-wrap items-center gap-2">
        <select wire:model.live="userId"
                class="rounded-control border border-ink-200 bg-white px-3 py-2 text-small text-ink-900 focus:border-signal focus:outline-none">
            <option value="">Anyone</option>
            @foreach ($people as $person)
                <option value="{{ $person->id }}">{{ $person->name }}</option>
            @endforeach
        </select>

        <select wire:model.live="action"
                class="rounded-control border border-ink-200 bg-white px-3 py-2 text-small text-ink-900 focus:border-signal focus:outline-none">
            <option value="">Anything</option>
            @foreach ($actions as $value)
                <option value="{{ $value }}">{{ ucfirst($describe($value)) }}</option>
            @endforeach
        </select>

        @if ($userId !== null || $action !== '')
            <button type="button" wire:click="clearFilters"
                    class="text-small font-semibold text-signal hover:text-signal-strong">Clear</button>
        @endif
    </div>

    <x-card flush>
        @forelse ($entries as $entry)
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1 border-b border-ink-100 px-4 py-3 last:border-0">
                <span class="text-small font-semibold text-ink-900">
                    {{ $entry->user?->name ?? 'A removed user' }}
                </span>

                <span class="text-small text-ink-700">{{ $describe($entry->action) }}</span>

                <time class="ml-auto shrink-0 text-small text-ink-500" data-numeric
                      datetime="{{ $entry->created_at?->toIso8601String() }}"
                      title="{{ $entry->created_at?->copy()->setTimezone($tz)->format('l j F Y, H:i:s') }}">
                    {{ $entry->created_at?->copy()->setTimezone($tz)->format('j M, H:i') }}
                </time>

                @if ($entry->changes)
                    <div class="w-full">
                        <p class="mt-1 break-words text-small text-ink-500">
                            @foreach ($entry->changes as $field => $change)
                                @php
                                    // A secret records that it changed, never to what.
                                    $redacted = is_array($change) && ($change['changed'] ?? false) === true;
                                    $value = is_array($change) ? ($change['to'] ?? null) : $change;
                                @endphp

                                <span class="mr-3 inline-block">
                                    <span class="font-mono text-ink-700">{{ $field }}</span>
                                    @if ($redacted)
                                        <span class="text-pending">replaced</span>
                                    @elseif (is_scalar($value) && $value !== null && $value !== '')
                                        → {{ Str::limit((string) $value, 40) }}
                                    @endif
                                </span>
                            @endforeach
                        </p>
                    </div>
                @endif
            </div>
        @empty
            <x-empty-state
                title="Nothing recorded yet"
                body="Every change is recorded here and cannot be edited or removed."
                icon="activity" />
        @endforelse
    </x-card>

    @if ($entries->hasPages())
        <div>{{ $entries->links() }}</div>
    @endif
</div>
