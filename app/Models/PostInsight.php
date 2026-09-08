<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InsightWindow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostInsight extends Model
{
    protected $fillable = [
        'post_target_id',
        'captured_at',
        'window',
        'impressions',
        'reach',
        'likes',
        'comments',
        'shares',
        'saves',
        'video_views',
        'engagement_rate',
    ];

    protected function casts(): array
    {
        return [
            'window' => InsightWindow::class,
            'captured_at' => 'datetime',
            'engagement_rate' => 'float',
        ];
    }

    public function postTarget(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class);
    }

    public function totalEngagements(): int
    {
        return (int) $this->likes
            + (int) $this->comments
            + (int) $this->shares
            + (int) $this->saves;
    }
}
