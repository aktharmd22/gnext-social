<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An approval or comment left through a shareable client review link.
 *
 * reviewer_hash is a salted digest, so repeat visits from the same recipient
 * are correlatable without storing anything identifying about them.
 */
class ReviewAction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'post_id',
        'reviewer_hash',
        'action',
        'note',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
