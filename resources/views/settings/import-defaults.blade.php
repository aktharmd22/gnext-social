<x-layouts.app title="Import defaults">

    <x-slot:header>
        <div>
            <h1 class="text-display">Settings</h1>
            <p class="mt-0.5 text-small text-ink-500">What the import wizard assumes before you override it.</p>
        </div>
    </x-slot:header>

    <x-settings-tabs />

    <div class="mt-4"><livewire:settings.import-defaults /></div>

</x-layouts.app>
