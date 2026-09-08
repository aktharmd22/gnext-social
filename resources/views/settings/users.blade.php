<x-layouts.app title="Users">

    <x-slot:header>
        <div>
            <h1 class="text-display">Settings</h1>
            <p class="mt-0.5 text-small text-ink-500">Who can sign in, and what they can do.</p>
        </div>
    </x-slot:header>

    <x-settings-tabs />

    <div class="mt-4"><livewire:settings.users /></div>

</x-layouts.app>
