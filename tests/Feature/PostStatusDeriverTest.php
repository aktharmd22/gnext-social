<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Publishing\PostStatusDeriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * posts.status is a summary of post_targets, never a source of truth.
 *
 * The case that matters is disagreement: Facebook published, Instagram failed.
 * Rounding that up to "Published" hides a failure; rounding it down to "Failed"
 * hides a post that is live in front of an audience.
 */
class PostStatusDeriverTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private PostStatusDeriver $deriver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->deriver = new PostStatusDeriver;
    }

    /**
     * @param  list<TargetStatus>  $targetStatuses
     */
    private function postWith(array $targetStatuses, PostStatus $current = PostStatus::Scheduled): Post
    {
        $post = Post::factory()->create([
            'workspace_id' => $this->workspace->id,
            'status' => $current,
        ]);

        foreach ($targetStatuses as $status) {
            $account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

            PostTarget::factory()->create([
                'post_id' => $post->id,
                'social_account_id' => $account->id,
                'status' => $status,
                'published_at' => $status === TargetStatus::Published ? now() : null,
            ]);
        }

        return $post->load('targets');
    }

    public function test_every_destination_published_is_published(): void
    {
        $post = $this->postWith([TargetStatus::Published, TargetStatus::Published]);

        $this->assertSame(PostStatus::Published, $this->deriver->derive($post));
    }

    /**
     * The whole reason this class exists.
     */
    public function test_one_published_and_one_failed_is_partly_published(): void
    {
        $post = $this->postWith([TargetStatus::Published, TargetStatus::Failed]);

        $this->assertSame(PostStatus::PartiallyPublished, $this->deriver->derive($post));
    }

    public function test_every_destination_failed_is_failed(): void
    {
        $post = $this->postWith([TargetStatus::Failed, TargetStatus::Failed]);

        $this->assertSame(PostStatus::Failed, $this->deriver->derive($post));
    }

    /**
     * Skipped means the account was disconnected, which is a cancellation
     * rather than something that went wrong.
     */
    public function test_every_destination_skipped_is_cancelled_not_failed(): void
    {
        $post = $this->postWith([TargetStatus::Skipped, TargetStatus::Skipped]);

        $this->assertSame(PostStatus::Cancelled, $this->deriver->derive($post));
    }

    public function test_published_alongside_skipped_is_partly_published(): void
    {
        $post = $this->postWith([TargetStatus::Published, TargetStatus::Skipped]);

        $this->assertSame(PostStatus::PartiallyPublished, $this->deriver->derive($post));
    }

    public function test_anything_mid_flight_reports_as_publishing(): void
    {
        $post = $this->postWith([TargetStatus::Published, TargetStatus::Publishing]);

        $this->assertSame(PostStatus::Publishing, $this->deriver->derive($post));
    }

    /**
     * Half done: one already live, one still queued. Saying "Scheduled" would
     * be a lie -- a caption is visible on Facebook right now.
     */
    public function test_published_alongside_still_queued_is_partly_published(): void
    {
        $post = $this->postWith([TargetStatus::Published, TargetStatus::Queued]);

        $this->assertSame(PostStatus::PartiallyPublished, $this->deriver->derive($post));
    }

    public function test_nothing_settled_yet_keeps_the_pre_publication_status(): void
    {
        $approved = $this->postWith([TargetStatus::Queued, TargetStatus::Queued], PostStatus::Approved);
        $this->assertSame(PostStatus::Approved, $this->deriver->derive($approved));

        $scheduled = $this->postWith([TargetStatus::Queued], PostStatus::Scheduled);
        $this->assertSame(PostStatus::Scheduled, $this->deriver->derive($scheduled));
    }

    public function test_a_post_with_no_destinations_is_left_alone(): void
    {
        $post = Post::factory()->create([
            'workspace_id' => $this->workspace->id,
            'status' => PostStatus::Draft,
        ]);

        $this->assertSame(PostStatus::Draft, $this->deriver->derive($post->load('targets')));
    }

    public function test_syncing_writes_the_status_and_stamps_published_at(): void
    {
        $post = $this->postWith([TargetStatus::Published, TargetStatus::Published]);

        $this->assertNull($post->published_at);

        $this->deriver->sync($post);

        $post->refresh();

        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertNotNull($post->published_at);
    }
}
