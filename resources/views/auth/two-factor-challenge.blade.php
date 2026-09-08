@extends('layouts.auth')

@section('title', 'Two-factor')
@section('heading', 'Confirm it is you')
@section('subheading', 'Enter the six-digit code from your authenticator app.')

@section('form')
    <div x-data="{ recovery: false }">
        <form method="POST" action="{{ route('two-factor.login.store') }}" class="space-y-4">
            @csrf

            <div x-show="!recovery">
                <x-field label="Authentication code" name="code" type="text" required
                         autofocus autocomplete="one-time-code" inputmode="numeric" />
            </div>

            <div x-show="recovery" x-cloak>
                <x-field label="Recovery code" name="recovery_code" type="text"
                         autocomplete="one-time-code"
                         hint="One of the codes you saved when you enrolled." />
            </div>

            <x-button type="submit" variant="primary" size="lg" class="w-full">Confirm</x-button>
        </form>

        <button type="button" @click="recovery = !recovery"
                class="mt-4 w-full text-center text-small font-semibold text-signal hover:text-signal-strong">
            <span x-show="!recovery">Use a recovery code instead</span>
            <span x-show="recovery" x-cloak>Use your authenticator app instead</span>
        </button>
    </div>
@endsection
