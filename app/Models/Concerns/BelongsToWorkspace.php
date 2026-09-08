<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\WorkspaceScope;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model to its workspace, and stamps workspace_id on create.
 *
 * The stamping half matters as much as the scoping half: without it, a model
 * created inside a scoped request would still need every call site to remember
 * the foreign key, and one forgotten call site is a cross-tenant leak.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function ($model): void {
            if ($model->getAttribute('workspace_id') !== null) {
                return;
            }

            $context = app(WorkspaceContext::class);

            if ($context->has()) {
                $model->setAttribute('workspace_id', $context->id());
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
