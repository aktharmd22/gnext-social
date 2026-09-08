<x-layouts.app title="Brand">

    <x-slot:header>
        <div>
            <h1 class="text-display">Settings</h1>
            <p class="mt-0.5 text-small text-ink-500">Logo, workspace timezone, and the footer appended at publish time.</p>
        </div>
    </x-slot:header>

    <x-settings-tabs />

    <div class="mt-4"><livewire:settings.brand /></div>

</x-layouts.app>
