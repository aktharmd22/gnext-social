<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Meta app this installation publishes through.
 *
 * Admin-editable at runtime so nothing is hardcoded to one app: the same build
 * can be pointed at a development app for testing and a reviewed production app
 * afterwards without a deploy.
 */
class AppCredential extends Model
{
    use HasFactory;

    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'meta_app_id',
        'meta_app_secret',
        'graph_version',
        'redirect_uri',
        'webhook_verify_token',
        'is_verified',
        'verified_at',
        'updated_by',
    ];

    /**
     * The secret is never serialised. The UI shows a masked value and a
     * "Replace secret" action; it is never rendered, returned or logged.
     *
     * @var list<string>
     */
    protected $hidden = [
        'meta_app_secret',
        'webhook_verify_token',
    ];

    protected function casts(): array
    {
        return [
            'meta_app_secret' => 'encrypted',
            'webhook_verify_token' => 'encrypted',
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Graph version prefix used to build every endpoint, e.g. "v21.0".
     */
    public function graphVersion(): string
    {
        return $this->graph_version ?: 'v21.0';
    }

    public function isUsable(): bool
    {
        return filled($this->meta_app_id) && filled($this->meta_app_secret);
    }
}
