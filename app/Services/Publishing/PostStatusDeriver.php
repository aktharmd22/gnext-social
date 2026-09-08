<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Models\Post;

/**
 * Reduces the post_targets rows to the one word the calendar shows.
 *
 * post_targets is the source of truth; posts.status is a summary of it. The
 * case that matters, and the reason this class exists rather than a column
 * update scattered through the publisher, is disagreement: Facebook published,
 * Instagram failed. That is `partially_published`, and it must never round up
 * to `published` or down to `failed` -- one hides a failure, the other hides a
 * live post.
 */
class PostStatusDeriver
{
    public function derive(Post $post): PostStatus
    {
        $targets = $post->relationLoaded('targets') ? $post->targets : $post->targets()->get();

        // No destinations yet: the post is whatever the composer says it is.
        if ($targets->isEmpty()) {
            return $post->status;
        }

        $counts = [
            TargetStatus::Published->value => 0,
            TargetStatus::Failed->value => 0,
            TargetStatus::Publishing->value => 0,
            TargetStatus::Queued->value => 0,
            TargetStatus::Skipped->value => 0,
        ];

        foreach ($targets as $target) {
            $counts[$target->status->value]++;
        }

        $total = $targets->count();
        $settled = $counts[TargetStatus::Published->value]
            + $counts[TargetStatus::Failed->value]
            + $counts[TargetStatus::Skipped->value];

        // Something is mid-flight at Meta right now.
        if ($counts[TargetStatus::Publishing->value] > 0) {
            return PostStatus::Publishing;
        }

        // Still waiting on its moment.
        if ($settled === 0) {
            return $this->pending($post);
        }

        // Every destination is done, one way or another.
        if ($settled === $total) {
            if ($counts[TargetStatus::Published->value] === 0) {
                // Nothing went out. Skipped-only means the accounts were
                // disconnected, which is a cancellation rather than a failure.
                return $counts[TargetStatus::Failed->value] > 0
                    ? PostStatus::Failed
                    : PostStatus::Cancelled;
            }

            $unpublished = $counts[TargetStatus::Failed->value] + $counts[TargetStatus::Skipped->value];

            return $unpublished > 0
                ? PostStatus::PartiallyPublished
                : PostStatus::Published;
        }

        /*
         * Some destinations are done and others are still queued. If anything
         * has already gone live, the post is partly published -- saying
         * "Scheduled" while a caption is visible on Facebook would be a lie.
         */
        return $counts[TargetStatus::Published->value] > 0
            ? PostStatus::PartiallyPublished
            : $this->pending($post);
    }

    /**
     * Apply the derived status, and the published_at stamp that goes with it.
     */
    public function sync(Post $post): Post
    {
        $status = $this->derive($post);

        $attributes = ['status' => $status];

        $firstPublished = $post->targets
            ->whereNotNull('published_at')
            ->sortBy('published_at')
            ->first();

        if ($firstPublished !== null && $post->published_at === null) {
            $attributes['published_at'] = $firstPublished->published_at;
        }

        $post->forceFill($attributes)->save();

        return $post;
    }

    /**
     * The pre-publication status, preserved rather than invented: an approved
     * post that has not gone out is still approved, not reset to scheduled.
     */
    private function pending(Post $post): PostStatus
    {
        return in_array($post->status, [
            PostStatus::Scheduled,
            PostStatus::Approved,
            PostStatus::Failed,
        ], true)
            ? $post->status
            : PostStatus::Scheduled;
    }
}
