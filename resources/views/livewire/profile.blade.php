<div class="grid min-w-0 gap-4 xl:grid-cols-2">

    <x-card title="Your details">
        <form wire:submit="saveProfile" class="space-y-4">
            <x-field label="Name" name="name" required>
                <x-slot:control>
                    <input id="name" type="text" wire:model="name"
                           class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-body
                                  text-ink-900 focus:border-signal focus:outline-none">
                </x-slot:control>
            </x-field>

            <x-field label="Email" name="email" required>
                <x-slot:control>
                    <input id="email" type="email" wire:model="email"
                           class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-body
                                  text-ink-900 focus:border-signal focus:outline-none">
                </x-slot:control>
            </x-field>

            <x-field label="Your timezone" name="timezone" required
                     hint="Every time in the product is shown in this zone, and labelled with it.">
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

            <x-button type="submit" variant="primary">Save</x-button>
        </form>
    </x-card>

    <div class="space-y-4">
        <x-card title="Password">
            <form wire:submit="changePassword" class="space-y-4">
                <x-field label="Current password" name="currentPassword" required>
                    <x-slot:control>
                        <input id="currentPassword" type="password" wire:model="currentPassword" autocomplete="current-password"
                               class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-body
                                      text-ink-900 focus:border-signal focus:outline-none">
                    </x-slot:control>
                </x-field>

                <x-field label="New password" name="password" required hint="At least 8 characters.">
                    <x-slot:control>
                        <input id="password" type="password" wire:model="password" autocomplete="new-password"
                               class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-body
                                      text-ink-900 focus:border-signal focus:outline-none">
                    </x-slot:control>
                </x-field>

                <x-field label="Confirm new password" name="passwordConfirmation" required>
                    <x-slot:control>
                        <input id="passwordConfirmation" type="password" wire:model="passwordConfirmation" autocomplete="new-password"
                               class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-body
                                      text-ink-900 focus:border-signal focus:outline-none">
                    </x-slot:control>
                </x-field>

                <x-button type="submit" variant="primary">Change password</x-button>
            </form>
        </x-card>

        <x-card title="Two-factor authentication">
            @if ($user->hasTwoFactorEnabled())
                <p class="flex items-center gap-2 text-small text-published">
                    <span aria-hidden="true">●</span> Enabled since {{ $user->two_factor_confirmed_at?->format('j M Y') }}.
                </p>
            @else
                <p class="flex items-start gap-2 text-small {{ $user->role->requiresTwoFactor() ? 'text-pending' : 'text-ink-500' }}">
                    <span aria-hidden="true" class="mt-px">{{ $user->role->requiresTwoFactor() ? '◐' : '○' }}</span>
                    <span>
                        Not set up.
                        @if ($user->role->requiresTwoFactor())
                            Admins hold the Page access tokens, so this is expected of your role.
                        @endif
                    </span>
                </p>
            @endif

            <p class="mt-3 text-small text-ink-500">
                Two-factor uses an authenticator app. Recovery codes are issued when you enrol —
                keep them somewhere other than the same phone.
            </p>
        </x-card>
    </div>
</div>
