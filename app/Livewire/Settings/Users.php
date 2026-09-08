<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\UserInvitedNotification;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Settings > Users. Invite, set a role, deactivate.
 *
 * Deactivate rather than delete, always: a departed colleague still authored
 * posts, approved content, and appears throughout the activity log. Deleting
 * them would tear holes in the record.
 */
class Users extends Component
{
    public bool $inviting = false;

    public string $name = '';

    public string $email = '';

    public string $role = 'user';

    public string $timezone = 'Asia/Dubai';

    public function mount(): void
    {
        Gate::authorize('manage-users');
    }

    public function startInviting(): void
    {
        $this->reset(['name', 'email', 'role', 'timezone']);
        $this->role = 'user';
        $this->timezone = auth()->user()->displayTimezone();
        $this->inviting = true;
        $this->resetValidation();
    }

    public function invite(ActivityLogger $log): void
    {
        Gate::authorize('manage-users');

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'role' => ['required', 'in:admin,user'],
            'timezone' => ['required', 'timezone'],
        ], [
            'email.unique' => 'Somebody already has an account with that address.',
        ]);

        $user = User::create([
            'workspace_id' => auth()->user()->workspace_id,
            'name' => $this->name,
            'email' => Str::lower($this->email),
            // Unusable until they set their own via the reset link.
            'password' => Str::random(48),
            'role' => $this->role,
            'timezone' => $this->timezone,
            'is_active' => true,
        ]);

        // The invitation is a password-reset link: one flow, already hardened,
        // rather than a second bespoke token system to get wrong.
        Password::sendResetLink(['email' => $user->email]);

        $log->log('user.invited', $user, ['email' => $user->email, 'role' => $this->role]);

        $this->inviting = false;
        $this->dispatch('toast', message: 'Invitation sent to '.$user->email.'.');
    }

    public function toggleActive(int $userId, ActivityLogger $log): void
    {
        Gate::authorize('manage-users');

        $user = User::query()->findOrFail($userId);

        if ($user->id === auth()->id()) {
            $this->dispatch('toast', message: 'You cannot deactivate your own account.');

            return;
        }

        $user->forceFill(['is_active' => ! $user->is_active])->save();

        $log->log($user->is_active ? 'user.reactivated' : 'user.deactivated', $user, [
            'email' => $user->email,
        ]);

        $this->dispatch('toast', message: $user->is_active
            ? $user->name.' can sign in again.'
            : $user->name.' can no longer sign in. Their work is kept.');
    }

    public function changeRole(int $userId, string $role, ActivityLogger $log): void
    {
        Gate::authorize('manage-users');

        $user = User::query()->findOrFail($userId);

        if ($user->id === auth()->id()) {
            $this->dispatch('toast', message: 'You cannot change your own role.');

            return;
        }

        $user->forceFill(['role' => $role])->save();

        $log->log('user.role_changed', $user, ['role' => $role]);

        $this->dispatch('toast', message: $user->name.' is now a '.Role::from($role)->label().'.');
    }

    public function render()
    {
        return view('livewire.settings.users', [
            'people' => User::query()->orderByDesc('is_active')->orderBy('name')->get(),
            'roles' => Role::cases(),
        ]);
    }
}
