<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Platform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostPlatformOverride extends Model
{
    protected $fillable = [
        'post_id',
        'platform',
        'caption',
        'first_comment',
    ];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
