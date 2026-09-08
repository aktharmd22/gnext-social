<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportBatchStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'filename',
        'stored_path',
        'mapping',
        'rows_total',
        'rows_imported',
        'rows_failed',
        'error_report_path',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportBatchStatus::class,
            'mapping' => 'array',
            'rows_total' => 'integer',
            'rows_imported' => 'integer',
            'rows_failed' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * Undo removes only what this batch created and only what has not gone out.
     * A published post is history and is never deleted by an undo.
     */
    public function undoablePosts(): HasMany
    {
        return $this->posts()->whereNull('published_at');
    }
}
