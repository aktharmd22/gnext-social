<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use BelongsToWorkspace;
    use HasFactory;
    use Notifiable;
    use TwoFactorAuthenticatable;

    protected $fillable = [
        'workspace_id',
        'name',
        'email',
        'password',
        'role',
        'timezone',
        'avatar_path',
        'is_active',
    ];

    /**
     * Never serialise a secret. two_factor_* are hidden as well as encrypted --
     * an accidental ->toArray() in a Livewire payload would otherwise ship them
     * to the browser.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'created_by');
    }

    public function approvedPosts(): HasMany
    {
        return $this->hasMany(Post::class, 'approved_by');
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /**
     * The zone this user reads times in. Falls back to the workspace default,
     * then to Asia/Dubai -- a bare time is never displayed anywhere.
     */
    public function displayTimezone(): string
    {
        return $this->timezone
            ?? $this->workspace?->timezone
            ?? config('gnext.default_timezone');
    }

    /**
     * Admins hold long-lived Page access tokens, so two-factor is not optional
     * for them. This drives the enrolment gate, not the login flow.
     */
    public function mustEnrolTwoFactor(): bool
    {
        return $this->role->requiresTwoFactor()
            && $this->two_factor_confirmed_at === null;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }
}
