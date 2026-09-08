<x-layouts.app title="Calendar events">

    <x-slot:header>
        <div>
            <h1 class="text-display">Settings</h1>
            <p class="mt-0.5 text-small text-ink-500">The UAE overlay drawn quietly behind the month grid.</p>
        </div>
    </x-slot:header>

    <x-settings-tabs />

    <div class="mt-4"><livewire:settings.calendar-events /></div>

</x-layouts.app>
