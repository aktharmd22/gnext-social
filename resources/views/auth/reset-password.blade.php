@extends('layouts.auth')

@section('title', 'Choose a new password')
@section('heading', 'Choose a new password')

@section('form')
    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-field label="Email" name="email" type="email" :value="$email" required autocomplete="username" />

        <x-field label="New password" name="password" type="password" required autocomplete="new-password"
                 hint="At least 8 characters." />

        <x-field label="Confirm new password" name="password_confirmation" type="password" required
                 autocomplete="new-password" />

        <x-button type="submit" variant="primary" size="lg" class="w-full">Save new password</x-button>
    </form>
@endsection
