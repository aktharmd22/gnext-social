<div class="space-y-4">
    <x-card title="Team" subtitle="Invite by email. Deactivate rather than delete — their work stays attributed.">
        <x-slot:actions>
            <x-button variant="primary" size="sm" wire:click="startInviting">Invite someone</x-button>
        </x-slot:actions>

        @if ($inviting)
            <div class="mb-4 space-y-3 rounded-control border border-signal-edge bg-signal-weak p-3">
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-field label="Name" name="name" required>
                        <x-slot:control>
                            <input id="name" type="text" wire:model="name"
                                   class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                          text-ink-900 focus:border-signal focus:outline-none">
                        </x-slot:control>
                    </x-field>

                    <x-field label="Email" name="email" required>
                        <x-slot:control>
                            <input id="email" type="email" wire:model="email"
                                   class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                          text-ink-900 focus:border-signal focus:outline-none">
                        </x-slot:control>
                    </x-field>

                    <div class="space-y-1.5">
                        <span class="block text-small font-semibold text-ink-900">Role</span>
                        @foreach ($roles as $option)
                            <label class="flex cursor-pointer items-start gap-2.5 rounded-control border px-3 py-2
                                          {{ $role === $option->value ? 'border-signal bg-white' : 'border-ink-200 bg-white hover:bg-ink-050' }}">
                                <input type="radio" wire:model.live="role" value="{{ $option->value }}"
                                       class="mt-0.5 size-4 border-ink-300 text-signal focus:ring-signal">
                                <span class="text-small">
                                    <span class="font-semibold text-ink-900">{{ $option->label() }}</span>
                                    <span class="block text-ink-500">{{ $option->description() }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <x-field label="Timezone" name="timezone" required>
                        <x-slot:control>
                            <input id="timezone" type="text" wire:model="timezone"
                                   class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                          text-ink-900 focus:border-signal focus:outline-none">
                        </x-slot:control>
                    </x-field>
                </div>

                <p class="text-small text-ink-500">
                    They receive a link to set their own password. No password is ever chosen for them.
                </p>

                <div class="flex gap-2">
                    <x-button variant="primary" size="sm" wire:click="invite">Send invitation</x-button>
                    <x-button variant="ghost" size="sm" wire:click="$set('inviting', false)">Cancel</x-button>
                </div>
            </div>
        @endif

        <div class="divide-y divide-ink-100">
            @foreach ($people as $person)
                <div wire:key="user-{{ $person->id }}" class="flex flex-wrap items-center gap-3 py-3 first:pt-0">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full text-micro font-semibold
                                 {{ $person->is_active ? 'bg-ink-900 text-white' : 'bg-ink-100 text-ink-500' }}">
                        {{ Str::of($person->name)->explode(' ')->take(2)->map(fn ($p) => Str::substr($p, 0, 1))->implode('') }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="truncate text-small font-semibold {{ $person->is_active ? 'text-ink-900' : 'text-ink-500' }}">
                            {{ $person->name }}
                            @if ($person->id === auth()->id())
                                <span class="ml-1 font-normal text-ink-500">you</span>
                            @endif
                        </p>
                        <p class="truncate text-small text-ink-500">{{ $person->email }}</p>
                    </div>

                    <div class="shrink-0 text-right">
                        @if ($person->is_active)
                            <span class="text-small text-ink-700">{{ $person->role->label() }}</span>
                            @if ($person->role->requiresTwoFactor() && ! $person->hasTwoFactorEnabled())
                                <span class="block text-micro font-semibold text-pending">Two-factor not set up</span>
                            @endif
                        @else
                            <span class="rounded-full bg-cancelled-soft px-2 py-[2px] text-micro font-semibold text-cancelled">
                                Deactivated
                            </span>
                        @endif
                    </div>

                    @if ($person->id !== auth()->id())
                        <div class="flex shrink-0 gap-1">
                            <select wire:change="changeRole({{ $person->id }}, $event.target.value)"
                                    class="rounded-control border border-ink-200 bg-white px-2 py-1 text-micro text-ink-700">
                                @foreach ($roles as $option)
                                    <option value="{{ $option->value }}" @selected($person->role === $option)>{{ $option->label() }}</option>
                                @endforeach
                            </select>

                            <button type="button" wire:click="toggleActive({{ $person->id }})"
                                    class="rounded-control px-2 py-1 text-micro font-semibold text-ink-500 hover:bg-ink-050 hover:text-ink-900">
                                {{ $person->is_active ? 'Deactivate' : 'Reactivate' }}
                            </button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-card>
</div>
