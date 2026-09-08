@extends('layouts.auth')

@section('title', 'Sign in')
@section('heading', 'Sign in')
@section('subheading', 'Publishing runs on a schedule. Sign in to change what goes out.')

@section('form')
    @if (session('status'))
        <div class="mb-4 rounded-control border border-published/25 bg-published-soft px-3 py-2.5 text-small text-published">
            {{ session('status') }}
        </div>
    @endif

    {{-- An expired session lands here rather than on a 419 page. --}}
    @if (session('error'))
        <div class="mb-4 flex items-start gap-2 rounded-control border border-pending/25 bg-pending-soft px-3 py-2.5 text-small text-pending">
            <span aria-hidden="true" class="mt-px">◐</span>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
        @csrf

        <x-field label="Email" name="email" type="email" required autofocus autocomplete="username" />

        <x-field label="Password" name="password" type="password" required autocomplete="current-password" />

        <div class="flex items-center justify-between gap-3 pt-0.5">
            <label class="flex items-center gap-2 text-small text-ink-700">
                <input type="checkbox" name="remember" value="1"
                       class="size-4 rounded border-ink-200 text-signal focus:ring-signal">
                Stay signed in
            </label>

            <a href="{{ route('password.request') }}" class="text-small font-semibold text-signal hover:text-signal-strong">
                Forgot password?
            </a>
        </div>

        <x-button type="submit" variant="primary" size="lg" class="w-full">Sign in</x-button>
    </form>
@endsection

@section('footer')
    Accounts are created by invitation. Ask an admin to add you.
@endsection
