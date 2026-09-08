<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Two roles, deliberately. No permissions package -- the capability matrix is
 * small enough that policies and gates express it more clearly than a table of
 * role/permission rows nobody will ever read.
 */
enum Role: string
{
    case Admin = 'admin';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::User => 'User',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Full access: connected accounts, tokens, approvals, publishing and logs.',
            self::User => 'Writes, schedules and submits content. Never sees a token or an app secret.',
        };
    }

    /**
     * Admins hold long-lived Page access tokens, so two-factor is not optional
     * for them.
     */
    public function requiresTwoFactor(): bool
    {
        return $this === self::Admin;
    }
}
