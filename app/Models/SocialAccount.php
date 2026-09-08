<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Platform;
use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One publishable destination: a Facebook Page, or an Instagram account.
 *
 * Long-lived Page tokens expire. Not "might" -- will. token_expires_at is
 * checked daily and drives the warning banner and email, because the failure
 * mode without it is silent: posts simply stop going out.
 */
class SocialAccount extends Model
{
    use HasFactory;

    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'platform',
        'page_id',
        'ig_user_id',
        'name',
        'username',
        'avatar_url',
        'access_token',
        'token_type',
        'token_expires_at',
        'scopes',
        'is_active',
        'last_checked_at',
        'last_error',
    ];

    /** @var list<string> */
    protected $hidden = [
        'access_token',
    ];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'access_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'scopes' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePlatform(Builder $query, Platform $platform): Builder
    {
        return $query->where('platform', $platform->value);
    }

    /**
     * The account label as a human refers to it: "Spark Tires FB".
     */
    public function displayName(): string
    {
        return $this->name.' '.$this->platform->shortLabel();
    }

    /**
     * Whole days until this token dies. Negative once it has.
     *
     * Rounded, not truncated: Carbon returns a float, so a token set to expire
     * in exactly five days measures 4.9999... and casting to int would report
     * four. Off-by-one here is off-by-one in a warning email.
     */
    public function tokenExpiresInDays(): ?int
    {
        if ($this->token_expires_at === null) {
            return null;
        }

        return (int) round(now()->diffInDays($this->token_expires_at, false));
    }

    public function tokenHasExpired(): bool
    {
        return $this->token_expires_at !== null
            && $this->token_expires_at->isPast();
    }

    /**
     * Whether the admin should be warned about this token now.
     */
    public function tokenNeedsAttention(): bool
    {
        $days = $this->tokenExpiresInDays();

        if ($days === null) {
            return false;
        }

        return $days <= max(config('gnext.tokens.warn_days'));
    }

    /**
     * Whether a publish attempt against this account can be expected to work.
     */
    public function isPublishable(): bool
    {
        return $this->is_active && ! $this->tokenHasExpired();
    }

    /**
     * The id Graph endpoints are built from. Instagram publishes against the
     * IG user id; Facebook against the Page id.
     */
    public function graphNodeId(): ?string
    {
        return $this->platform === Platform::Instagram
            ? $this->ig_user_id
            : $this->page_id;
    }

    public function lastCheckedAt(): ?CarbonInterface
    {
        return $this->last_checked_at;
    }
}
