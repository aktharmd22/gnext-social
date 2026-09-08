<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Livewire\Approvals;
use App\Models\ActivityLog;
use App\Models\Post;
use App\Models\ReviewAction;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    private User $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create(['timezone' => 'Asia/Dubai']);
        $this->admin = User::factory()->admin()->create(['workspace_id' => $this->workspace->id]);
        $this->writer = User::factory()->create(['workspace_id' => $this->workspace->id]);
    }

    private function pendingPost(): Post
    {
        return Post::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->writer->id,
            'title' => 'Team spotlight',
            'caption' => 'Meet Reem, who has run the Al Quoz counter for six years.',
            'status' => PostStatus::PendingApproval,
            'scheduled_at' => now()->addDays(3),
        ]);
    }

    // =============================================================== queue

    public function test_the_queue_shows_what_is_waiting(): void
    {
        $this->pendingPost();

        Livewire::actingAs($this->admin)
            ->test(Approvals::class)
            ->assertSee('Team spotlight')
            ->assertSee('Approve');
    }

    public function test_a_draft_is_not_in_the_queue(): void
    {
        Post::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Still a draft',
            'status' => PostStatus::Draft,
        ]);

        Livewire::actingAs($this->admin)
            ->test(Approvals::class)
            ->assertDontSee('Still a draft');
    }

    /**
     * A post still waiting past its own scheduled time has already missed it.
     * Silence there would be the failure.
     */
    public function test_a_post_stuck_past_its_slot_is_called_out(): void
    {
        Post::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->writer->id,
            'status' => PostStatus::PendingApproval,
            'scheduled_at' => now()->subHours(3),
        ]);

        Livewire::actingAs($this->admin)
            ->test(Approvals::class)
            ->assertSee('is still waiting')
            ->assertSee('will not publish until approved');
    }

    // ============================================================ decisions

    public function test_an_admin_approves_and_it_becomes_publishable(): void
    {
        $post = $this->pendingPost();

        Livewire::actingAs($this->admin)
            ->test(Approvals::class)
            ->call('approve', $post->id);

        $post->refresh();

        $this->assertSame(PostStatus::Approved, $post->status);
        $this->assertSame($this->admin->id, $post->approved_by);
        $this->assertNotNull($post->approved_at);

        // Approved posts are picked up by the dispatcher alongside scheduled.
        $this->assertTrue(Post::query()->due(now()->addDays(4))->whereKey($post->id)->exists());
    }

    /**
     * Approving your own work defeats the point of an approval step.
     */
    public function test_a_writer_cannot_approve(): void
    {
        $post = $this->pendingPost();

        Livewire::actingAs($this->writer)
            ->test(Approvals::class)
            ->call('approve', $post->id)
            ->assertForbidden();

        $this->assertSame(PostStatus::PendingApproval, $post->fresh()->status);
    }

    public function test_requesting_changes_sends_it_back_with_a_note(): void
    {
        $post = $this->pendingPost();

        Livewire::actingAs($this->admin)
            ->test(Approvals::class)
            ->call('startRequestingChanges', $post->id)
            ->set('note', 'Swap the hero image for the wide crop.')
            ->call('requestChanges')
            ->assertHasNoErrors();

        $post->refresh();

        // Back to the author, not cancelled: the work is not wasted.
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertSame('Swap the hero image for the wide crop.', $post->rejection_note);
        $this->assertNull($post->approved_by);
    }

    public function test_a_rejection_must_say_why(): void
    {
        $post = $this->pendingPost();

        Livewire::actingAs($this->admin)
            ->test(Approvals::class)
            ->call('startRequestingChanges', $post->id)
            ->set('note', '')
            ->call('requestChanges')
            ->assertHasErrors('note');

        $this->assertSame(PostStatus::PendingApproval, $post->fresh()->status);
    }

    public function test_decisions_are_recorded_in_the_activity_log(): void
    {
        $post = $this->pendingPost();

        Livewire::actingAs($this->admin)->test(Approvals::class)->call('approve', $post->id);

        $entry = ActivityLog::withoutGlobalScopes()->where('action', 'post.approved')->firstOrFail();

        $this->assertSame($this->admin->id, $entry->user_id);
        $this->assertSame($post->id, $entry->subject_id);
    }

    // ========================================================= review link

    public function test_a_share_link_is_signed_and_uses_the_uuid(): void
    {
        $post = $this->pendingPost();

        $component = Livewire::actingAs($this->admin)
            ->test(Approvals::class)
            ->call('shareLink', $post->id);

        $link = $component->get('shareLinks')[$post->id];

        $this->assertStringContainsString($post->public_uuid, $link);
        $this->assertStringContainsString('signature=', $link);
        $this->assertStringContainsString('expires=', $link);

        // The sequential id must not leak: it tells a client how much you post.
        // Compared on the exact path segment, because a uuid beginning with the
        // same digit would make a substring check pass or fail by luck.
        $path = parse_url($link, PHP_URL_PATH);

        $this->assertSame('/review/'.$post->public_uuid, $path);
        $this->assertNotSame('/review/'.$post->id, $path);
    }

    public function test_the_review_page_opens_without_an_account(): void
    {
        $post = $this->pendingPost();

        $this->get($this->signedLink($post))
            ->assertOk()
            ->assertSee('Ready for your review')
            ->assertSee('Meet Reem');

        $this->assertGuest();
    }

    public function test_an_unsigned_link_is_refused(): void
    {
        $post = $this->pendingPost();

        $this->get('/review/'.$post->public_uuid)->assertForbidden();
    }

    public function test_a_tampered_link_is_refused(): void
    {
        $post = $this->pendingPost();

        $this->get($this->signedLink($post).'&tampered=1')->assertForbidden();
    }

    public function test_an_expired_link_is_refused(): void
    {
        $post = $this->pendingPost();

        $link = URL::temporarySignedRoute('review.show', now()->subDay(), ['uuid' => $post->public_uuid]);

        $this->get($link)->assertForbidden();
    }

    public function test_a_client_can_approve_without_logging_in(): void
    {
        $post = $this->pendingPost();

        $this->post($this->signedDecideLink($post), ['action' => 'approve'])
            ->assertRedirect();

        $action = ReviewAction::query()->firstOrFail();

        $this->assertSame('approve', $action->action);
        $this->assertSame($post->id, $action->post_id);
    }

    /**
     * A client approval is a signal, not a publish authorisation. A forwarded
     * link must not be able to put content live.
     */
    public function test_a_client_approval_does_not_publish_anything(): void
    {
        $post = $this->pendingPost();

        $this->post($this->signedDecideLink($post), ['action' => 'approve']);

        $this->assertSame(
            PostStatus::PendingApproval,
            $post->fresh()->status,
            'The post must stay in the internal queue.'
        );
    }

    public function test_a_comment_must_carry_a_note(): void
    {
        $post = $this->pendingPost();

        $this->post($this->signedDecideLink($post), ['action' => 'comment'])
            ->assertSessionHasErrors('note');

        $this->assertSame(0, ReviewAction::query()->count());
    }

    /**
     * Repeat visits are correlatable without storing anything identifying.
     */
    public function test_the_reviewer_is_recorded_pseudonymously(): void
    {
        $post = $this->pendingPost();

        $this->post($this->signedDecideLink($post), ['action' => 'approve']);

        $action = ReviewAction::query()->firstOrFail();

        // A salted digest, not an identity.
        $this->assertSame(64, strlen($action->reviewer_hash));
        $this->assertStringNotContainsString('127.0.0.1', $action->reviewer_hash);
    }

    public function test_the_same_reviewer_hashes_differently_per_post(): void
    {
        $first = $this->pendingPost();
        $second = $this->pendingPost();

        $this->post($this->signedDecideLink($first), ['action' => 'approve']);
        $this->post($this->signedDecideLink($second), ['action' => 'approve']);

        $hashes = ReviewAction::query()->pluck('reviewer_hash');

        $this->assertNotSame(
            $hashes[0],
            $hashes[1],
            'One person reviewing two posts should not be linkable across them.'
        );
    }

    public function test_the_client_response_appears_in_the_internal_queue(): void
    {
        $post = $this->pendingPost();

        $this->post($this->signedDecideLink($post), [
            'action' => 'comment',
            'note' => 'Could we use the wider crop instead?',
        ]);

        Livewire::actingAs($this->admin)
            ->test(Approvals::class)
            ->assertSee('From the review link')
            ->assertSee('Could we use the wider crop instead?');
    }

    public function test_the_review_link_is_rate_limited(): void
    {
        $post = $this->pendingPost();
        $link = $this->signedDecideLink($post);

        for ($i = 0; $i < 10; $i++) {
            $this->post($link, ['action' => 'approve']);
        }

        $this->post($link, ['action' => 'approve'])
            ->assertSessionHas('error');

        $this->assertSame(10, ReviewAction::query()->count());
    }

    // =========================================================== activity

    public function test_the_activity_log_is_admin_only(): void
    {
        $this->actingAs($this->writer)->get('/activity')->assertForbidden();
        $this->actingAs($this->admin)->get('/activity')->assertOk();
    }

    private function signedLink(Post $post): string
    {
        return URL::temporarySignedRoute('review.show', now()->addDays(14), ['uuid' => $post->public_uuid]);
    }

    private function signedDecideLink(Post $post): string
    {
        return URL::temporarySignedRoute('review.decide', now()->addDays(14), ['uuid' => $post->public_uuid]);
    }
}
