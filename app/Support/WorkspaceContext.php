<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * Which workspace the current execution belongs to.
 *
 * In a web request this is set once by middleware from the authenticated user.
 * In a queued job or a console command there is no authenticated user, so the
 * job sets it explicitly before touching any scoped model.
 *
 * When nothing has set it, models are NOT scoped. That is deliberate and is
 * relied on by exactly two callers -- the scheduler, which sweeps due targets
 * across every workspace, and the migration/seed path. Everything reachable
 * from HTTP passes through middleware that sets it, so a web request can never
 * see another workspace by accident.
 */
final class WorkspaceContext
{
    private ?int $workspaceId = null;

    public function set(?int $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
    }

    public function id(): ?int
    {
        return $this->workspaceId;
    }

    public function has(): bool
    {
        return $this->workspaceId !== null;
    }

    public function forget(): void
    {
        $this->workspaceId = null;
    }

    /**
     * Run a callback pinned to one workspace, then restore whatever was set.
     *
     * Used by jobs that fan out across workspaces: each iteration is pinned so
     * that a model saved inside the callback picks up the right workspace_id.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(int $workspaceId, Closure $callback): mixed
    {
        $previous = $this->workspaceId;
        $this->workspaceId = $workspaceId;

        try {
            return $callback();
        } finally {
            $this->workspaceId = $previous;
        }
    }

    /**
     * Run a callback with scoping disabled entirely.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runUnscoped(Closure $callback): mixed
    {
        $previous = $this->workspaceId;
        $this->workspaceId = null;

        try {
            return $callback();
        } finally {
            $this->workspaceId = $previous;
        }
    }
}
