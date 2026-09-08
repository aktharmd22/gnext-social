{{-- The one people actually hit: a form left open while the session lapsed. --}}
<x-errors.layout code="419" title="That page had been sitting too long"
    body="For your security the form expired, so nothing was submitted. Open it again and it will work.">

    <x-slot:actions>
        <a href="{{ url()->previous() }}"
           class="inline-flex items-center justify-center rounded-control bg-signal px-3.5 py-2 text-small
                  font-semibold text-white hover:bg-signal-strong">
            Try again
        </a>
        <a href="{{ route('login') }}"
           class="inline-flex items-center justify-center rounded-control border border-ink-200 bg-white px-3.5 py-2
                  text-small font-semibold text-ink-700 hover:bg-ink-050">
            Sign in
        </a>
    </x-slot:actions>
</x-errors.layout>
