<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TargetStatus;
use App\Models\PostTarget;
use App\Services\Publishing\PublishOrchestrator;
use App\Support\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Publish one post to one destination.
 *
 * Per target, never per post: a failure at Instagram must not block the
 * Facebook sibling, and retrying one must not republish the other.
 *
 * Unique by target id, because the per-minute dispatcher and the recovery
 * command can both see the same due row. The unique lock plus the database
 * unique constraint on (post_id, social_account_id) are what stop the same
 * caption reaching the same page twice -- the single most embarrassing failure
 * this product could produce.
 */
class PublishPostTargetJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * One attempt per dispatch. Retries are owned by the orchestrator, which
     * knows the difference between an expired token and a rate limit; the queue
     * would retry both identically.
     */
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $postTargetId) {}

    public function uniqueId(): string
    {
        return 'publish-target-'.$this->postTargetId;
    }

    /**
     * Long enough to cover the slowest realistic Instagram video container.
     */
    public function uniqueFor(): int
    {
        return 900;
    }

    public function handle(PublishOrchestrator $orchestrator, WorkspaceContext $workspace): void
    {
        $target = PostTarget::query()
            ->with(['post.media', 'post.overrides', 'socialAccount'])
            ->find($this->postTargetId);

        if ($target === null) {
            return;
        }

        // Already dealt with by another worker, or cancelled while queued.
        if (! $target->status->isPending()) {
            return;
        }

        $workspaceId = $target->post?->workspace_id;

        if ($workspaceId === null) {
            return;
        }

        // A job has no authenticated user, so the workspace is established
        // explicitly before anything scoped is touched.
        $workspace->runFor($workspaceId, fn () => $orchestrator->publish($target));
    }

    public function failed(\Throwable $exception): void
    {
        $target = PostTarget::find($this->postTargetId);

        if ($target === null || ! $target->status->isPending()) {
            return;
        }

        $target->forceFill([
            'status' => TargetStatus::Failed,
            'error_code' => 'worker_crashed',
            'error_message' => 'The publishing worker stopped unexpectedly: '.$exception->getMessage(),
            'last_attempt_at' => now(),
        ])->save();
    }
}
