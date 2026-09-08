<x-errors.layout code="403" title="Not yours to open"
    body="Your account does not have access to this. If you think it should, ask an admin on your team.">

    <x-slot:actions>
        <a href="{{ url('/calendar') }}"
           class="inline-flex items-center justify-center rounded-control bg-signal px-3.5 py-2 text-small
                  font-semibold text-white hover:bg-signal-strong">
            Back to the calendar
        </a>
    </x-slot:actions>
</x-errors.layout>
