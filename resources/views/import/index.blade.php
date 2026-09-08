<x-layouts.app title="Import">

    <x-slot:header>
        <div>
            <h1 class="text-display">Import</h1>
            <p class="mt-0.5 text-small text-ink-500">
                Upload a spreadsheet, map its columns, check the dry run, then commit.
            </p>
        </div>
    </x-slot:header>

    <livewire:import-wizard />

</x-layouts.app>
