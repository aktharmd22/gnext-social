<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PostSource;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One piece of content, before it is fanned out to destinations.
 *
 * `status` is a SUMMARY of the post_targets rows, not a source of truth. Read
 * it for display; derive it with PostStatusDeriver after any target changes.
 */
class Post extends Model
{
    /** @use HasFactory<\Database\Factories\PostFactory> */
    use BelongsToWorkspace;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'title',
        'caption',
        'caption_ar',
        'type',
        'first_comment',
        'append_brand_footer',
        'scheduled_at',
        'published_at',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'rejection_note',
        'source',
        'import_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => PostType::class,
            'status' => PostStatus::class,
            'source' => PostSource::class,
            'append_brand_footer' => 'boolean',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Post $post): void {
            $post->public_uuid ??= (string) Str::uuid();
        });
    }

    // ------------------------------------------------------------ relations

    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('position');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(PostPlatformOverride::class);
    }

    public function reviewActions(): HasMany
    {
        return $this->hasMany(ReviewAction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    // --------------------------------------------------------------- scopes

    /**
     * Everything visible in one month of the calendar.
     *
     * Deliberately a single range query. The month grid renders from one query
     * plus eager loads -- never a query per day.
     */
    public function scopeScheduledBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->whereBetween('scheduled_at', [$from, $to]);
    }

    public function scopeStatus(Builder $query, PostStatus ...$statuses): Builder
    {
        return $query->whereIn('status', array_map(fn (PostStatus $s) => $s->value, $statuses));
    }

    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('status', PostStatus::PendingApproval->value);
    }

    /**
     * Posts whose moment has arrived and which have not gone out.
     */
    public function scopeDue(Builder $query, ?\DateTimeInterface $asOf = null): Builder
    {
        return $query
            ->whereIn('status', [PostStatus::Scheduled->value, PostStatus::Approved->value])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $asOf ?? now());
    }

    // -------------------------------------------------------------- helpers

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function isReschedulable(): bool
    {
        return $this->status->isReschedulable();
    }

    /**
     * A post must never reach Scheduled with media that is not ready: Meta
     * fetches the bytes itself, so an unresolved link is a guaranteed 09:00
     * failure rather than a warning in the composer.
     */
    public function hasUnreadyMedia(): bool
    {
        return $this->media->contains(fn (PostMedia $m) => ! $m->status->isReady());
    }

    /**
     * The caption for one platform, honouring any per-platform override.
     */
    public function captionFor(\App\Enums\Platform $platform): ?string
    {
        $override = $this->overrides->firstWhere('platform', $platform);

        return $override?->caption ?: $this->caption;
    }

    public function firstCommentFor(\App\Enums\Platform $platform): ?string
    {
        $override = $this->overrides->firstWhere('platform', $platform);

        return $override?->first_comment ?: $this->first_comment;
    }

    /**
     * Was this published later than it was meant to be? Set when the recovery
     * command picks up a post the worker missed, so a late publish is visible
     * rather than silently indistinguishable from an on-time one.
     */
    public function wasPublishedLate(): bool
    {
        if ($this->published_at === null || $this->scheduled_at === null) {
            return false;
        }

        return $this->published_at->gt($this->scheduled_at->addMinutes(5));
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
