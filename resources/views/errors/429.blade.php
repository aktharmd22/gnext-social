<x-errors.layout code="429" title="Too many attempts"
    body="You have tried that more times than we allow in a minute. Wait a moment and try again.">

    <x-slot:actions>
        <a href="{{ route('login') }}"
           class="inline-flex items-center justify-center rounded-control border border-ink-200 bg-white px-3.5 py-2
                  text-small font-semibold text-ink-700 hover:bg-ink-050">
            Back to sign in
        </a>
    </x-slot:actions>
</x-errors.layout>
