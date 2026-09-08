<x-card title="Import defaults" subtitle="What the wizard starts from. It still asks on every import.">
    <form wire:submit="save" class="space-y-5">

        <div class="grid gap-5 sm:grid-cols-2">
            <x-field label="Default publish time" name="defaultTime" required
                     hint="Used for any row without its own time.">
                <x-slot:control>
                    <input id="defaultTime" type="time" wire:model="defaultTime"
                           class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-body
                                  tabular-nums text-ink-900 focus:border-signal focus:outline-none">
                </x-slot:control>
            </x-field>

            <div class="space-y-1.5">
                <span class="block text-small font-semibold text-ink-900">Usual date format</span>

                @foreach (['DD-MM-YYYY' => 'Day first — 03-04 is 3 April', 'MM-DD-YYYY' => 'Month first — 03-04 is 4 March'] as $value => $label)
                    <label class="flex cursor-pointer items-start gap-2.5 rounded-control border px-3 py-2
                                  {{ $dateFormat === $value ? 'border-signal bg-signal-weak' : 'border-ink-200 hover:bg-ink-050' }}">
                        <input type="radio" wire:model.live="dateFormat" value="{{ $value }}"
                               class="mt-0.5 size-4 border-ink-300 text-signal focus:ring-signal">
                        <span class="text-small {{ $dateFormat === $value ? 'font-semibold text-signal' : 'text-ink-700' }}">{{ $label }}</span>
                    </label>
                @endforeach

                <p class="text-small text-ink-500">
                    A starting point only. The wizard asks every time, because getting this wrong
                    puts a month of content on the wrong days.
                </p>
            </div>
        </div>

        <div class="space-y-1.5 border-t border-ink-100 pt-4">
            <span class="block text-small font-semibold text-ink-900">Default destinations</span>

            @forelse ($accounts as $account)
                <label class="flex cursor-pointer items-center gap-2.5 rounded-control border border-ink-200 px-3 py-2 hover:bg-ink-050">
                    <input type="checkbox" wire:model="accountIds" value="{{ $account->id }}"
                           class="size-4 rounded border-ink-300 text-signal focus:ring-signal">
                    <x-platform-glyph :platform="$account->platform" class="size-4" />
                    <span class="truncate text-small text-ink-900">{{ $account->name }}</span>
                </label>
            @empty
                <p class="text-small text-ink-500">No accounts connected yet.</p>
            @endforelse
        </div>

        <x-button type="submit" variant="primary">Save</x-button>
    </form>
</x-card>
