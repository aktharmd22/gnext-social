<x-errors.layout code="404" title="Nothing here"
    body="That page does not exist, or the post it pointed at has been deleted.">

    <x-slot:actions>
        <a href="{{ url('/calendar') }}"
           class="inline-flex items-center justify-center rounded-control bg-signal px-3.5 py-2 text-small
                  font-semibold text-white hover:bg-signal-strong">
            Back to the calendar
        </a>
    </x-slot:actions>
</x-errors.layout>
