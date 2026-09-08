<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

/**
 * Who may do what to a post.
 *
 * Reading is team-wide: the calendar exists so the whole team can see a month
 * of content at a glance, and a calendar that hides a colleague's posts cannot
 * do that. Writing is owner-scoped: a user edits, deletes and submits their own
 * work, an admin edits anyone's.
 *
 * Approval, force-publish and retry are admin-only regardless of authorship --
 * approving your own post defeats the point of an approval step.
 */
class PostPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Post $post): bool
    {
        return $this->sameWorkspace($user, $post);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Post $post): bool
    {
        if (! $this->sameWorkspace($user, $post)) {
            return false;
        }

        // A post mid-flight at Meta is not editable by anyone, including an
        // admin: the job has already read the caption it is publishing.
        if (! $post->status->isEditable()) {
            return false;
        }

        return $user->isAdmin() || $post->created_by === $user->id;
    }

    public function delete(User $user, Post $post): bool
    {
        if (! $this->sameWorkspace($user, $post)) {
            return false;
        }

        return $user->isAdmin() || $post->created_by === $user->id;
    }

    public function restore(User $user, Post $post): bool
    {
        return $this->delete($user, $post);
    }

    /**
     * Move a chip to a different day.
     */
    public function reschedule(User $user, Post $post): bool
    {
        if (! $post->status->isReschedulable()) {
            return false;
        }

        return $this->update($user, $post);
    }

    /**
     * Send a draft into the approval queue. Authors submit their own work.
     */
    public function submit(User $user, Post $post): bool
    {
        return $this->sameWorkspace($user, $post)
            && ($user->isAdmin() || $post->created_by === $user->id);
    }

    public function approve(User $user, Post $post): bool
    {
        return $user->isAdmin() && $this->sameWorkspace($user, $post);
    }

    public function reject(User $user, Post $post): bool
    {
        return $this->approve($user, $post);
    }

    /**
     * Publish immediately, or retry a failed target.
     */
    public function publishNow(User $user, Post $post): bool
    {
        return $user->isAdmin() && $this->sameWorkspace($user, $post);
    }

    /**
     * The raw Graph request and response for every attempt. Admin only: even
     * redacted, these expose endpoint structure and account identifiers.
     */
    public function viewLogs(User $user, Post $post): bool
    {
        return $user->isAdmin() && $this->sameWorkspace($user, $post);
    }

    private function sameWorkspace(User $user, Post $post): bool
    {
        return $user->workspace_id === $post->workspace_id;
    }
}
