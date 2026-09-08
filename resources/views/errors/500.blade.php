<x-errors.layout code="500" title="Something broke on our side"
    body="This is not your fault and nothing you were doing has been lost. It has been logged.">

    <x-slot:extra>
        <p class="rounded-control border border-ink-100 bg-ink-050 p-3 text-small text-ink-500">
            Scheduled posts are unaffected — publishing runs on a worker, not in this request.
        </p>
    </x-slot:extra>

    <x-slot:actions>
        <a href="{{ url('/calendar') }}"
           class="inline-flex items-center justify-center rounded-control bg-signal px-3.5 py-2 text-small
                  font-semibold text-white hover:bg-signal-strong">
            Back to the calendar
        </a>
    </x-slot:actions>
</x-errors.layout>
