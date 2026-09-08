<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The approvals queue.
 *
 * Everything an admin needs to decide without opening anything else: the media,
 * both platform previews, and the two actions. Approving your own work is not
 * possible -- that is enforced by policy, not by hiding a button.
 */
class Approvals extends Component
{
    public ?int $decidingOn = null;

    public string $note = '';

    /** @var array<int, string> */
    public array $shareLinks = [];

    public function approve(int $postId, ActivityLogger $log): void
    {
        $post = Post::query()->findOrFail($postId);

        Gate::authorize('approve', $post);

        $post->forceFill([
            // Approved, not Scheduled: the dispatcher treats both as due, and
            // "Approved" says who decided rather than merely when it goes out.
            'status' => PostStatus::Approved,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'rejection_note' => null,
        ])->save();

        $log->log('post.approved', $post, ['title' => $post->title]);

        $this->dispatch('posts:changed');
        $this->dispatch('toast', message: 'Approved. It will publish at its scheduled time.');
    }

    public function startRequestingChanges(int $postId): void
    {
        $this->decidingOn = $postId;
        $this->note = '';
    }

    public function cancelRequestingChanges(): void
    {
        $this->decidingOn = null;
        $this->note = '';
    }

    public function requestChanges(ActivityLogger $log): void
    {
        $post = Post::query()->findOrFail($this->decidingOn);

        Gate::authorize('reject', $post);

        $this->validate([
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ], [
            'note.required' => 'Say what needs changing — "rejected" on its own helps nobody.',
        ]);

        $post->forceFill([
            // Back to the author, not cancelled: the work is not wasted.
            'status' => PostStatus::Draft,
            'rejection_note' => $this->note,
            'approved_by' => null,
            'approved_at' => null,
        ])->save();

        $log->log('post.changes_requested', $post, ['note' => $this->note]);

        $this->decidingOn = null;
        $this->note = '';

        $this->dispatch('posts:changed');
        $this->dispatch('toast', message: 'Sent back to the author with your note.');
    }

    /**
     * A signed, expiring, read-only URL an outside client can open without an
     * account.
     *
     * Keyed on the post's uuid rather than its id: the signature makes it safe
     * either way, but handing a client /review/47 leaks how much you publish.
     */
    public function shareLink(int $postId): void
    {
        $post = Post::query()->findOrFail($postId);

        Gate::authorize('view', $post);

        $this->shareLinks[$postId] = URL::temporarySignedRoute(
            'review.show',
            now()->addDays((int) config('gnext.review.link_ttl_days', 14)),
            ['uuid' => $post->public_uuid],
        );
    }

    #[On('posts:changed')]
    public function refreshQueue(): void
    {
        //
    }

    public function render()
    {
        $posts = Post::query()
            ->with(['media', 'targets.socialAccount', 'creator', 'reviewActions'])
            ->awaitingApproval()
            ->orderBy('scheduled_at')
            ->get();

        return view('livewire.approvals', [
            'posts' => $posts,
            'tz' => auth()->user()->displayTimezone(),
        ]);
    }
}
