<div class="space-y-4">

    {{-- ================================================================= --}}
    {{-- Credentials                                                       --}}
    {{-- ================================================================= --}}
    <x-card title="Meta app"
            subtitle="The app this installation publishes through. Nothing here is hardcoded to one app.">

        <form wire:submit="save" class="space-y-5">

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="App ID" name="metaAppId" required
                         hint="All digits, from the top of your app dashboard.">
                    <x-slot:control>
                        <input id="metaAppId" type="text" inputmode="numeric" wire:model="metaAppId"
                               autocomplete="off" placeholder="1234567890123456"
                               class="w-full rounded-control border bg-white px-3 py-2 text-body text-ink-900 tabular-nums
                                      placeholder:text-ink-500 focus:outline-none
                                      {{ $errors->has('metaAppId') ? 'border-failed' : 'border-ink-200 focus:border-signal' }}">
                    </x-slot:control>
                </x-field>

                <x-field label="Graph version" name="graphVersion" required
                         hint="v21.0 or later. Instagram publishing needs v21.0+.">
                    <x-slot:control>
                        <input id="graphVersion" type="text" wire:model="graphVersion"
                               autocomplete="off" placeholder="v21.0"
                               class="w-full rounded-control border bg-white px-3 py-2 text-body text-ink-900
                                      placeholder:text-ink-500 focus:outline-none
                                      {{ $errors->has('graphVersion') ? 'border-failed' : 'border-ink-200 focus:border-signal' }}">
                    </x-slot:control>
                </x-field>
            </div>

            {{-- The secret is write-only. The stored value is never loaded into
                 the component, so it cannot reach the browser even in a
                 Livewire payload. --}}
            <div class="space-y-1.5">
                <label for="newSecret" class="block text-small font-semibold text-ink-900">
                    App secret
                    @if (! $hasStoredSecret)
                        <span class="ml-1 font-normal text-failed">required</span>
                    @endif
                </label>

                @if ($hasStoredSecret && ! $replacingSecret)
                    <div class="flex flex-wrap items-center gap-3 rounded-control border border-ink-200 bg-ink-050 px-3 py-2">
                        <span class="font-mono text-body tracking-widest text-ink-500">••••••••••••••••</span>
                        <span class="text-small text-ink-500">Stored and encrypted.</span>
                        <button type="button" wire:click="startReplacingSecret"
                                class="ml-auto text-small font-semibold text-signal hover:text-signal-strong">
                            Replace secret
                        </button>
                    </div>
                    <p class="text-small text-ink-500">
                        The stored secret is never shown again, to anyone. Replace it if you have rotated it in Meta.
                    </p>
                @else
                    <input id="newSecret" type="password" wire:model="newSecret"
                           autocomplete="new-password" placeholder="Paste the app secret"
                           class="w-full rounded-control border bg-white px-3 py-2 text-body text-ink-900
                                  placeholder:text-ink-500 focus:outline-none
                                  {{ $errors->has('newSecret') ? 'border-failed' : 'border-ink-200 focus:border-signal' }}">

                    <div class="flex items-center justify-between gap-3">
                        <p class="text-small text-ink-500">Encrypted at rest. It will not be displayed again.</p>
                        @if ($hasStoredSecret)
                            <button type="button" wire:click="cancelReplacingSecret"
                                    class="shrink-0 text-small font-semibold text-ink-500 hover:text-ink-900">
                                Keep the current secret
                            </button>
                        @endif
                    </div>
                @endif

                @error('newSecret')
                    <p class="flex items-start gap-1.5 text-small text-failed">
                        <span aria-hidden="true" class="leading-[1.35]">✕</span>
                        <span>{{ $message }}</span>
                    </p>
                @enderror
            </div>

            {{-- Copy-ready, because this exact string has to be pasted into the
                 Meta dashboard and a trailing slash breaks the whole flow. --}}
            <div class="space-y-1.5" x-data="{ copied: false }">
                <label for="redirectUri" class="block text-small font-semibold text-ink-900">
                    OAuth redirect URI <span class="ml-1 font-normal text-ink-500">required</span>
                </label>

                <div class="flex gap-2">
                    <input id="redirectUri" type="url" wire:model="redirectUri" readonly
                           class="w-full rounded-control border border-ink-200 bg-ink-050 px-3 py-2 text-small
                                  text-ink-700 focus:border-signal focus:outline-none">

                    <button type="button"
                            x-on:click="navigator.clipboard.writeText($refs.uri ? $refs.uri.value : document.getElementById('redirectUri').value); copied = true; setTimeout(() => copied = false, 1800)"
                            class="shrink-0 rounded-control border border-ink-200 bg-white px-3 py-2 text-small font-semibold
                                   text-ink-700 hover:bg-ink-050 hover:text-ink-900">
                        <span x-show="!copied">Copy</span>
                        <span x-show="copied" x-cloak class="text-published">Copied</span>
                    </button>
                </div>

                <p class="text-small text-ink-500">
                    Paste this into your Meta app under Facebook Login → Settings → Valid OAuth Redirect URIs.
                    It must match exactly, including the scheme.
                </p>

                @error('redirectUri')
                    <p class="text-small text-failed">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-wrap items-center gap-2 border-t border-ink-100 pt-4">
                <x-button type="submit" variant="primary">
                    <span wire:loading.remove wire:target="save">Save settings</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-button>

                <x-button type="button" variant="secondary" wire:click="testConnection">
                    <span wire:loading.remove wire:target="testConnection">Test connection</span>
                    <span wire:loading wire:target="testConnection">Testing…</span>
                </x-button>

                <p class="text-small text-ink-500">
                    Testing calls Meta’s <span class="font-mono text-ink-700">debug_token</span> and reports what it finds.
                </p>
            </div>
        </form>
    </x-card>

    {{-- ================================================================= --}}
    {{-- Test result                                                       --}}
    {{-- ================================================================= --}}
    @if ($testResult)
        @php $ok = $testResult['ok']; @endphp

        <div class="rounded-card border p-4
                    {{ $ok ? 'border-published/25 bg-published-soft' : 'border-failed/25 bg-failed-soft' }}">
            <p class="flex items-center gap-2 text-h2 {{ $ok ? 'text-published' : 'text-failed' }}">
                <span aria-hidden="true">{{ $ok ? '●' : '✕' }}</span>
                {{ $testResult['headline'] }}
            </p>

            <p class="mt-1.5 text-small text-ink-700">{{ $testResult['detail'] }}</p>

            @if ($ok && ! empty($testResult['expires']))
                <p class="mt-1 text-small text-ink-500">
                    This app token is valid until <time data-numeric>{{ $testResult['expires'] }}</time>.
                </p>
            @endif

            @if ($ok && ! empty($testResult['missing']))
                <div class="mt-3 rounded-control border border-pending/25 bg-white p-3">
                    <p class="text-small font-semibold text-pending">
                        Not yet granted: {{ implode(', ', $testResult['missing']) }}
                    </p>
                    <p class="mt-1 text-small text-ink-500">
                        These are granted when you connect a Page, and some require Meta App Review
                        plus Business Verification before they work outside development mode.
                    </p>
                </div>
            @endif

            @if ($ok)
                <div class="mt-3">
                    <x-button variant="primary" size="sm" :href="route('settings.accounts')">
                        Connect a Page
                    </x-button>
                </div>
            @endif
        </div>
    @endif

    {{-- ================================================================= --}}
    {{-- What this needs, stated once, so setup does not fail mysteriously --}}
    {{-- ================================================================= --}}
    <x-card title="Before Instagram publishing will work">
        <ul class="space-y-2.5 text-small text-ink-700">
            @foreach ([
                'The Instagram account must be a Business or Creator account, not personal.',
                'It must be linked to the Facebook Page you connect here.',
                'Your Meta app needs instagram_basic, instagram_content_publish, pages_show_list, pages_read_engagement and pages_manage_posts.',
                'Publishing outside development mode needs Meta App Review and Business Verification.',
            ] as $requirement)
                <li class="flex items-start gap-2.5">
                    <span aria-hidden="true" class="mt-[3px] text-ink-300">◷</span>
                    <span>{{ $requirement }}</span>
                </li>
            @endforeach
        </ul>

        <p class="mt-4 border-t border-ink-100 pt-3 text-small text-ink-500">
            While your app is in development mode you can publish to Pages you administer.
            That is enough to verify this end to end before review completes.
        </p>
    </x-card>
</div>
