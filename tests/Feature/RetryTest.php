<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Jobs\PublishPostTargetJob;
use App\Livewire\Dashboard;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Publishing\PublishOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Retrying one failed destination of a partially published post.
 *
 * This is the shape that broke: a post whose Facebook destination published
 * and whose Instagram destination failed derives to PartiallyPublished. The
 * retry used to only reset the row to Queued and leave the rest to
 * gnext:dispatch-due -- which selects on Post::due(), so it only ever picks up
 * Scheduled or Approved posts and never touched it again. The row sat Queued
 * forever, and because it no longer read as Failed it also disappeared from
 * the needs-attention list, so doing nothing looked like success.
 */
class RetryTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    private Post $post;

    private PostTarget $failed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create(['timezone' => 'Asia/Dubai']);
        $this->admin = User::factory()->admin()->create(['workspace_id' => $this->workspace->id]);

        $facebook = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
        $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);

        $this->post = Post::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->admin->id,
            'scheduled_at' => now()->subHour(),
            'status' => PostStatus::PartiallyPublished,
            'published_at' => now()->subHour(),
        ]);

        PostTarget::factory()->create([
            'post_id' => $this->post->id,
            'social_account_id' => $facebook->id,
            'status' => TargetStatus::Published,
            'external_id' => 'fbpost-1',
            'published_at' => now()->subHour(),
        ]);

        $this->failed = PostTarget::factory()->create([
            'post_id' => $this->post->id,
            'social_account_id' => $instagram->id,
            'status' => TargetStatus::Failed,
            'attempts' => 3,
            'error_code' => 'graph_36003',
            'error_message' => 'The image is not a valid aspect ratio for this media type.',
        ]);
    }

    public function test_retrying_actually_queues_the_destination(): void
    {
        Queue::fake();

        app(PublishOrchestrator::class)->retry($this->failed);

        Queue::assertPushed(
            PublishPostTargetJob::class,
            fn (PublishPostTargetJob $job) => true
        );
    }

    public function test_retrying_clears_the_previous_failure(): void
    {
        Queue::fake();

        app(PublishOrchestrator::class)->retry($this->failed);

        $fresh = $this->failed->fresh();

        $this->assertSame(TargetStatus::Queued, $fresh->status);
        $this->assertSame(0, $fresh->attempts);
        $this->assertNull($fresh->error_code);
        $this->assertNull($fresh->error_message);
    }

    /**
     * The scheduler must not be the thing that rescues a retry. It selects on
     * Post::due(), and a PartiallyPublished post is not due -- which is exactly
     * why the retry has to dispatch for itself.
     */
    public function test_the_scheduler_alone_would_never_pick_this_up(): void
    {
        $this->failed->forceFill([
            'status' => TargetStatus::Queued,
            'attempts' => 0,
        ])->save();

        $this->assertSame(
            0,
            PostTarget::query()->dispatchable()->count(),
            'A queued destination on a partially published post is not dispatchable, '
            .'so retry() must queue the job itself.'
        );
    }

    public function test_the_dashboard_offers_retry_and_it_queues(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(Dashboard::class)
            ->assertSee('Retry')
            ->call('retryTarget', $this->failed->id)
            ->assertHasNoErrors();

        Queue::assertPushed(PublishPostTargetJob::class);

        $this->assertSame(TargetStatus::Queued, $this->failed->fresh()->status);
    }

    public function test_a_writer_cannot_retry(): void
    {
        Queue::fake();

        $writer = User::factory()->create(['workspace_id' => $this->workspace->id]);

        Livewire::actingAs($writer)
            ->test(Dashboard::class)
            ->call('retryTarget', $this->failed->id)
            ->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertSame(TargetStatus::Failed, $this->failed->fresh()->status);
    }

    /**
     * Retrying Instagram must never republish the Facebook destination that
     * already went out. One retry, one destination.
     */
    public function test_retrying_does_not_touch_a_destination_that_succeeded(): void
    {
        Queue::fake();

        $published = $this->post->targets()->where('status', TargetStatus::Published->value)->firstOrFail();

        app(PublishOrchestrator::class)->retry($this->failed);

        $this->assertSame(TargetStatus::Published, $published->fresh()->status);
        $this->assertSame('fbpost-1', $published->fresh()->external_id);

        Queue::assertPushed(PublishPostTargetJob::class, 1);
    }
}
