@extends('layouts.auth')

@section('title', 'Reset your password')
@section('heading', 'Reset your password')
@section('subheading', 'We will email you a link. It expires in an hour.')

@section('form')
    @if (session('status'))
        <div class="mb-4 rounded-control border border-published/25 bg-published-soft px-3 py-2.5 text-small text-published">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <x-field label="Email" name="email" type="email" required autofocus autocomplete="username" />

        <x-button type="submit" variant="primary" size="lg" class="w-full">Email me a link</x-button>
    </form>
@endsection

@section('footer')
    <a href="{{ route('login') }}" class="font-semibold text-signal hover:text-signal-strong">Back to sign in</a>
@endsection
