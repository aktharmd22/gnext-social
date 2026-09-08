<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Component;

/**
 * Your own name, timezone, password and two-factor state.
 */
class Profile extends Component
{
    public string $name = '';

    public string $email = '';

    public string $timezone = '';

    public string $currentPassword = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public function mount(): void
    {
        $user = auth()->user();

        $this->name = $user->name;
        $this->email = $user->email;
        $this->timezone = $user->displayTimezone();
    }

    public function saveProfile(ActivityLogger $log): void
    {
        $user = auth()->user();

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user->id)],
            'timezone' => ['required', 'timezone'],
        ]);

        $user->fill([
            'name' => $this->name,
            'email' => $this->email,
            'timezone' => $this->timezone,
        ])->save();

        $log->logChanges('profile.updated', $user);

        $this->dispatch('toast', message: 'Profile saved.');
    }

    public function changePassword(ActivityLogger $log): void
    {
        $this->validate([
            'currentPassword' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ], [
            'currentPassword.current_password' => 'That is not your current password.',
            'password.confirmed' => 'The two new passwords do not match.',
        ], [
            'password_confirmation' => 'confirmation',
        ]);

        auth()->user()->forceFill(['password' => Hash::make($this->password)])->save();

        $log->log('profile.password_changed', auth()->user());

        $this->reset(['currentPassword', 'password', 'passwordConfirmation']);

        $this->dispatch('toast', message: 'Password changed.');
    }

    public function render()
    {
        return view('livewire.profile', [
            'user' => auth()->user(),
            'zones' => [
                'Asia/Dubai', 'Asia/Riyadh', 'Asia/Qatar', 'Asia/Kuwait',
                'Asia/Karachi', 'Asia/Kolkata', 'Europe/London', 'UTC',
            ],
        ]);
    }
}
