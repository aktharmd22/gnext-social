<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query to the workspace the current execution belongs to.
 *
 * Applied automatically by the BelongsToWorkspace trait. No-ops when no
 * workspace has been established, which is how the cross-workspace scheduler
 * and the seeders operate.
 */
final class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(WorkspaceContext::class);

        if (! $context->has()) {
            return;
        }

        $builder->where(
            $model->qualifyColumn('workspace_id'),
            $context->id()
        );
    }
}
