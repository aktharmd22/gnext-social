<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit trail. created_at only -- a log entry that can be updated is
 * not a log entry.
 */
class ActivityLog extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'subject_type',
        'subject_id',
        'action',
        'changes',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject()
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_id');
    }
}
