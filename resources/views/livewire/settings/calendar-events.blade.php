<x-card title="Calendar overlay" subtitle="Drawn quietly behind the month grid. Editable, because Hijri dates move every year.">
    <x-slot:actions>
        <x-button variant="secondary" size="sm" wire:click="newEvent">Add an event</x-button>
    </x-slot:actions>

    @if ($editing !== null)
        <div class="mb-4 space-y-3 rounded-control border border-signal-edge bg-signal-weak p-3">
            <div class="grid gap-3 sm:grid-cols-2">
                <x-field label="Name" name="name" required>
                    <x-slot:control>
                        <input id="name" type="text" wire:model="name" placeholder="Eid al-Fitr"
                               class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                      text-ink-900 focus:border-signal focus:outline-none">
                    </x-slot:control>
                </x-field>

                <x-field label="Name in Arabic" name="nameAr">
                    <x-slot:control>
                        <input id="nameAr" type="text" wire:model="nameAr" dir="rtl" lang="ar"
                               class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                      text-ink-900 focus:border-signal focus:outline-none">
                    </x-slot:control>
                </x-field>

                <x-field label="Starts" name="startsOn" required>
                    <x-slot:control>
                        <input id="startsOn" type="date" wire:model="startsOn"
                               class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                      tabular-nums text-ink-900 focus:border-signal focus:outline-none">
                    </x-slot:control>
                </x-field>

                <x-field label="Ends" name="endsOn" required>
                    <x-slot:control>
                        <input id="endsOn" type="date" wire:model="endsOn"
                               class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                      tabular-nums text-ink-900 focus:border-signal focus:outline-none">
                    </x-slot:control>
                </x-field>

                <x-field label="Kind" name="kind" required>
                    <x-slot:control>
                        <select id="kind" wire:model="kind"
                                class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                       text-ink-900 focus:border-signal focus:outline-none">
                            @foreach ($kinds as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                    </x-slot:control>
                </x-field>
            </div>

            <label class="flex items-start gap-2.5">
                <input type="checkbox" wire:model="isApproximate"
                       class="mt-0.5 size-4 rounded border-ink-300 text-signal focus:ring-signal">
                <span class="text-small text-ink-700">
                    The date is provisional
                    <span class="block text-ink-500">
                        Shown with an asterisk. Hijri dates are confirmed by moon sighting, and showing
                        a provisional Eid as fixed is worse than showing it as provisional.
                    </span>
                </span>
            </label>

            <div class="flex gap-2">
                <x-button variant="primary" size="sm" wire:click="save">Save</x-button>
                <x-button variant="ghost" size="sm" wire:click="cancel">Cancel</x-button>
            </div>
        </div>
    @endif

    <div class="divide-y divide-ink-100">
        @forelse ($events as $event)
            <div wire:key="event-{{ $event->id }}" class="flex flex-wrap items-center gap-3 py-2.5 first:pt-0">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-small font-semibold {{ $event->is_active ? 'text-ink-900' : 'text-ink-500' }}">
                        {{ $event->name }}@if ($event->is_approximate)<span class="text-ink-500">*</span>@endif
                        @if ($event->name_ar)
                            <span class="ml-1.5 font-normal text-ink-500" dir="rtl" lang="ar">{{ $event->name_ar }}</span>
                        @endif
                    </p>
                    <p class="text-small text-ink-500" data-numeric>
                        {{ $event->starts_on->format('j M Y') }} – {{ $event->ends_on->format('j M Y') }}
                        · {{ $event->kind->label() }}
                    </p>
                </div>

                <div class="flex shrink-0 gap-1">
                    <button type="button" wire:click="toggleActive({{ $event->id }})"
                            class="rounded-control px-2 py-1 text-micro font-semibold text-ink-500 hover:bg-ink-050 hover:text-ink-900">
                        {{ $event->is_active ? 'Hide' : 'Show' }}
                    </button>
                    <button type="button" wire:click="edit({{ $event->id }})"
                            class="rounded-control px-2 py-1 text-micro font-semibold text-ink-500 hover:bg-ink-050 hover:text-ink-900">Edit</button>
                    <button type="button" wire:click="delete({{ $event->id }})" wire:confirm="Remove this from the calendar?"
                            class="rounded-control px-2 py-1 text-micro font-semibold text-ink-500 hover:bg-ink-050 hover:text-failed">Delete</button>
                </div>
            </div>
        @empty
            <x-empty-state
                title="Nothing on the overlay"
                body="Ramadan, both Eids, National Day, back-to-school and peak summer usually live here."
                icon="calendar" />
        @endforelse
    </div>
</x-card>
