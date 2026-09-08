<div class="space-y-4">
    <x-card title="Brand" subtitle="How this workspace identifies itself, and the clock it runs on.">
        <form wire:submit="save" class="space-y-5">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Workspace name" name="name" required>
                    <x-slot:control>
                        <input id="name" type="text" wire:model="name"
                               class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-body
                                      text-ink-900 focus:border-signal focus:outline-none">
                    </x-slot:control>
                </x-field>

                <x-field label="Workspace timezone" name="timezone" required
                         hint="The default for anyone who has not set their own.">
                    <x-slot:control>
                        <select id="timezone" wire:model="timezone"
                                class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-body
                                       text-ink-900 focus:border-signal focus:outline-none">
                            @foreach ($zones as $zone)
                                <option value="{{ $zone }}">{{ $zone }}</option>
                            @endforeach
                        </select>
                    </x-slot:control>
                </x-field>
            </div>

            <x-button type="submit" variant="primary">Save</x-button>
        </form>
    </x-card>

    <x-card title="Brand footer" subtitle="Appended when a post publishes, not pasted into every caption.">
        @if ($footer)
            <p class="whitespace-pre-line rounded-control border border-ink-100 bg-ink-050 p-3 text-small text-ink-700" dir="auto">{{ $footer->body }}</p>
            <p class="mt-2 text-small text-ink-500">
                Change a phone number here and it changes on everything published from now on.
            </p>
        @else
            <p class="text-small text-ink-500">No footer is set, so nothing is appended at publish time.</p>
        @endif

        <div class="mt-3">
            <x-button variant="secondary" size="sm" :href="route('templates.index')">
                {{ $footer ? 'Edit it in Templates' : 'Create one in Templates' }}
            </x-button>
        </div>
    </x-card>
</div>
