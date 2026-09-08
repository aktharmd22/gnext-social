<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Graph API attempt: what we sent, what came back, how long it took.
 *
 * request_payload arrives here already redacted. An access token must never
 * reach this table.
 */
class PublishLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'post_target_id',
        'attempt',
        'endpoint',
        'request_payload',
        'response_body',
        'http_code',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_body' => 'array',
            'attempt' => 'integer',
            'http_code' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function postTarget(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class);
    }

    public function succeeded(): bool
    {
        return $this->http_code !== null && $this->http_code < 400;
    }
}
