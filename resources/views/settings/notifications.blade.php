<x-layouts.app title="Notifications">

    <x-slot:header>
        <div>
            <h1 class="text-display">Settings</h1>
            <p class="mt-0.5 text-small text-ink-500">Where a failure at 06:00 actually reaches someone.</p>
        </div>
    </x-slot:header>

    <x-settings-tabs />

    <div class="mt-4"><livewire:settings.notifications /></div>

</x-layouts.app>
