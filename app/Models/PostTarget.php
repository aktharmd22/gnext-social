<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TargetStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One post at one destination. The source of truth for what actually happened.
 *
 * Not workspace-scoped directly: it reaches its workspace through the post, and
 * a second scope on the same query would only add a redundant join.
 */
class PostTarget extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'social_account_id',
        'status',
        'external_id',
        'permalink',
        'container_id',
        'attempts',
        'last_attempt_at',
        'error_code',
        'error_message',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TargetStatus::class,
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function insights(): HasMany
    {
        return $this->hasMany(PostInsight::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PublishLog::class)->orderBy('attempt');
    }

    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('status', TargetStatus::Queued->value);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', TargetStatus::Failed->value);
    }

    /**
     * Targets whose post is due and which have not been attempted to
     * exhaustion. This is what the per-minute dispatcher selects on.
     */
    public function scopeDispatchable(Builder $query, ?\DateTimeInterface $asOf = null): Builder
    {
        return $query
            ->where('status', TargetStatus::Queued->value)
            ->where('attempts', '<', config('gnext.publishing.max_attempts'))
            ->whereHas('post', fn (Builder $p) => $p->due($asOf));
    }

    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < (int) config('gnext.publishing.max_attempts');
    }

    public function isPublished(): bool
    {
        return $this->status === TargetStatus::Published;
    }
}
