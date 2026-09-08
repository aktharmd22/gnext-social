<div class="space-y-4">
    <x-card title="Notifications" subtitle="A failure at 06:00 should reach a phone, not an inbox nobody opens until 09:00.">
        <form wire:submit="save" class="space-y-5">

            <div class="space-y-2">
                <label class="flex items-start gap-2.5">
                    <input type="checkbox" wire:model="emailOnFailure"
                           class="mt-0.5 size-4 rounded border-ink-300 text-signal focus:ring-signal">
                    <span class="text-small text-ink-700">
                        Email when a post fails to publish
                        <span class="block text-ink-500">With the cause in plain language and a link to retry.</span>
                    </span>
                </label>

                <label class="flex items-start gap-2.5">
                    <input type="checkbox" wire:model="emailOnPublish"
                           class="mt-0.5 size-4 rounded border-ink-300 text-signal focus:ring-signal">
                    <span class="text-small text-ink-700">
                        Email on every successful publish
                        <span class="block text-ink-500">Off by default. It is a lot of mail.</span>
                    </span>
                </label>
            </div>

            <x-field label="Alert recipients" name="recipients"
                     hint="Comma separated. Leave empty to alert every admin.">
                <x-slot:control>
                    <input id="recipients" type="text" wire:model="recipients"
                           placeholder="you@example.com, someone@example.com"
                           class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                  text-ink-900 focus:border-signal focus:outline-none">
                </x-slot:control>
            </x-field>

            <div class="space-y-4 border-t border-ink-100 pt-4">
                <p class="text-small font-semibold text-ink-900">Telegram</p>

                <x-field label="Chat ID" name="telegramChatId"
                         hint="Message your bot, then read the chat id from getUpdates.">
                    <x-slot:control>
                        <input id="telegramChatId" type="text" wire:model="telegramChatId"
                               class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                      tabular-nums text-ink-900 focus:border-signal focus:outline-none">
                    </x-slot:control>
                </x-field>

                <div class="space-y-1.5">
                    <span class="block text-small font-semibold text-ink-900">Bot token</span>

                    @if ($hasStoredToken && ! $replacingToken)
                        <div class="flex flex-wrap items-center gap-3 rounded-control border border-ink-200 bg-ink-050 px-3 py-2">
                            <span class="font-mono text-body tracking-widest text-ink-500">••••••••••••</span>
                            <span class="text-small text-ink-500">Stored and encrypted.</span>
                            <button type="button" wire:click="startReplacingToken"
                                    class="ml-auto text-small font-semibold text-signal hover:text-signal-strong">Replace</button>
                        </div>
                    @else
                        <input type="password" wire:model="telegramBotToken" autocomplete="new-password"
                               placeholder="123456789:AA..."
                               class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                      text-ink-900 focus:border-signal focus:outline-none">
                        <p class="text-small text-ink-500">Encrypted at rest, like every other secret here.</p>
                    @endif
                </div>
            </div>

            <div class="border-t border-ink-100 pt-4">
                <x-field label="Webhook URL" name="webhookUrl"
                         hint="Any endpoint that accepts a JSON POST. Slack and Teams both do.">
                    <x-slot:control>
                        <input id="webhookUrl" type="url" wire:model="webhookUrl"
                               placeholder="https://hooks.example.com/…"
                               class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                      text-ink-900 focus:border-signal focus:outline-none">
                    </x-slot:control>
                </x-field>
            </div>

            <div class="flex flex-wrap items-center gap-2 border-t border-ink-100 pt-4">
                <x-button type="submit" variant="primary">Save</x-button>
                <x-button type="button" variant="secondary" wire:click="sendTest">Send a test alert</x-button>
                <p class="text-small text-ink-500">
                    A channel that is configured but broken is worse than one that is off.
                </p>
            </div>
        </form>
    </x-card>

    @if ($testResult)
        <div class="rounded-card border p-4
                    {{ $testResult['ok'] ? 'border-published/25 bg-published-soft' : 'border-pending/25 bg-pending-soft' }}">
            <p class="text-small {{ $testResult['ok'] ? 'text-published' : 'text-pending' }}">
                {{ $testResult['detail'] }}
            </p>
        </div>
    @endif
</div>
