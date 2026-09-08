<x-layouts.app title="Meta app">

    <x-slot:header>
        <div>
            <h1 class="text-display">Settings</h1>
            <p class="mt-0.5 text-small text-ink-500">How this installation talks to Meta.</p>
        </div>
    </x-slot:header>

    <x-settings-tabs />

    @if (session('error'))
        <div class="mt-4 rounded-card border border-failed/25 bg-failed-soft px-4 py-3 text-small text-failed">
            {{ session('error') }}
        </div>
    @endif

    <div class="mt-4">
        @livewire('settings.meta-app')
    </div>

</x-layouts.app>
