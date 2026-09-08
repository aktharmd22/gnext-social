<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostSource;
use App\Enums\PostStatus;
use App\Livewire\Posts;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class BulkOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    private User $writer;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create(['timezone' => 'Asia/Dubai']);
        $this->admin = User::factory()->admin()->create([
            'workspace_id' => $this->workspace->id,
            'timezone' => 'Asia/Dubai',
        ]);
        $this->writer = User::factory()->create([
            'workspace_id' => $this->workspace->id,
            'timezone' => 'Asia/Dubai',
        ]);

        $this->account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    }

    private function makePost(array $attributes = []): Post
    {
        return Post::factory()->create(array_merge([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->admin->id,
            'scheduled_at' => Carbon::parse('2026-10-14 09:00', 'Asia/Dubai')->utc(),
            'status' => PostStatus::Scheduled,
        ], $attributes));
    }

    public function test_shifting_moves_every_selection_and_keeps_the_time(): void
    {
        $a = $this->makePost(['scheduled_at' => Carbon::parse('2026-10-14 19:30', 'Asia/Dubai')->utc()]);
        $b = $this->makePost(['scheduled_at' => Carbon::parse('2026-10-16 08:00', 'Asia/Dubai')->utc()]);

        Livewire::actingAs($this->admin)
            ->test(Posts::class)
            ->set('selected', [$a->id, $b->id])
            ->set('shiftDays', 7)
            ->call('shiftSelected');

        $movedA = $a->fresh()->scheduled_at->copy()->setTimezone('Asia/Dubai');
        $movedB = $b->fresh()->scheduled_at->copy()->setTimezone('Asia/Dubai');

        $this->assertSame('2026-10-21', $movedA->toDateString());
        $this->assertSame('19:30', $movedA->format('H:i'), 'The time of day must survive a shift.');

        $this->assertSame('2026-10-23', $movedB->toDateString());
        $this->assertSame('08:00', $movedB->format('H:i'));
    }

    public function test_shifting_backwards_works_too(): void
    {
        $post = $this->makePost();

        Livewire::actingAs($this->admin)
            ->test(Posts::class)
            ->set('selected', [$post->id])
            ->set('shiftDays', -3)
            ->call('shiftSelected');

        $this->assertSame(
            '2026-10-11',
            $post->fresh()->scheduled_at->copy()->setTimezone('Asia/Dubai')->toDateString()
        );
    }

    public function test_retiming_sets_the_hour_and_keeps_each_date(): void
    {
        $a = $this->makePost(['scheduled_at' => Carbon::parse('2026-10-14 09:00', 'Asia/Dubai')->utc()]);
        $b = $this->makePost(['scheduled_at' => Carbon::parse('2026-10-20 15:00', 'Asia/Dubai')->utc()]);

        Livewire::actingAs($this->admin)
            ->test(Posts::class)
            ->set('selected', [$a->id, $b->id])
            ->set('bulkTime', '18:45')
            ->call('retimeSelected');

        foreach ([$a, $b] as $post) {
            $this->assertSame('18:45', $post->fresh()->scheduled_at->copy()->setTimezone('Asia/Dubai')->format('H:i'));
        }

        $this->assertSame('2026-10-14', $a->fresh()->scheduled_at->copy()->setTimezone('Asia/Dubai')->toDateString());
        $this->assertSame('2026-10-20', $b->fresh()->scheduled_at->copy()->setTimezone('Asia/Dubai')->toDateString());
    }

    /**
     * A published post is history. Bulk actions never rewrite it.
     */
    public function test_a_published_post_is_skipped_rather_than_moved(): void
    {
        $published = $this->makePost([
            'status' => PostStatus::Published,
            'published_at' => now()->subDay(),
        ]);

        $original = $published->scheduled_at;

        Livewire::actingAs($this->admin)
            ->test(Posts::class)
            ->set('selected', [$published->id])
            ->call('shiftSelected');

        $this->assertEquals($original, $published->fresh()->scheduled_at);
    }

    /**
     * Nine of twelve moved, three refused, and the operator is told which.
     * Silently doing nothing, or silently doing everything, are both wrong.
     */
    public function test_a_partial_result_says_what_was_skipped(): void
    {
        $mine = $this->makePost(['created_by' => $this->writer->id]);
        $theirs = $this->makePost(['created_by' => $this->admin->id]);

        Livewire::actingAs($this->writer)
            ->test(Posts::class)
            ->set('selected', [$mine->id, $theirs->id])
            ->call('shiftSelected')
            ->assertDispatched('toast', function (string $event, array $params) {
                return str_contains($params['message'], '1 moved')
                    && str_contains($params['message'], '1 skipped');
            });

        // Theirs is untouched.
        $this->assertSame('2026-10-14', $theirs->fresh()->scheduled_at->copy()->setTimezone('Asia/Dubai')->toDateString());
    }

    public function test_bulk_approve_is_admin_only(): void
    {
        $post = $this->makePost(['status' => PostStatus::PendingApproval, 'created_by' => $this->writer->id]);

        Livewire::actingAs($this->writer)
            ->test(Posts::class)
            ->set('selected', [$post->id])
            ->call('approveSelected');

        $this->assertSame(PostStatus::PendingApproval, $post->fresh()->status);

        Livewire::actingAs($this->admin)
            ->test(Posts::class)
            ->set('selected', [$post->id])
            ->call('approveSelected');

        $this->assertSame(PostStatus::Approved, $post->fresh()->status);
    }

    /**
     * Duplicating a month of content straight into the queue would publish it
     * unreviewed, so copies land as drafts.
     */
    public function test_duplicating_copies_a_month_forward_as_drafts(): void
    {
        $post = $this->makePost();

        PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $this->account->id,
        ]);

        PostMedia::factory()->create(['post_id' => $post->id]);

        Livewire::actingAs($this->admin)
            ->test(Posts::class)
            ->set('selected', [$post->id])
            ->call('duplicateSelected');

        $copy = Post::query()->where('id', '!=', $post->id)->firstOrFail();

        $this->assertSame(PostStatus::Draft, $copy->status);
        $this->assertSame(PostSource::Duplicate, $copy->source);
        $this->assertSame($post->caption, $copy->caption);

        $this->assertSame(
            '2026-11-14',
            $copy->scheduled_at->copy()->setTimezone('Asia/Dubai')->toDateString()
        );

        // Destinations and media come with it.
        $this->assertSame(1, $copy->targets()->count());
        $this->assertSame(1, $copy->media()->count());
    }

    /**
     * The copy points at the already-fetched file rather than re-downloading a
     * Drive link that may since have been revoked.
     */
    public function test_a_duplicate_reuses_the_fetched_media_file(): void
    {
        $post = $this->makePost();

        $media = PostMedia::factory()->create([
            'post_id' => $post->id,
            'stored_path' => 'media/2026/09/original.jpg',
            'public_url' => 'https://gnext.test/storage/media/2026/09/original.jpg',
        ]);

        Livewire::actingAs($this->admin)
            ->test(Posts::class)
            ->set('selected', [$post->id])
            ->call('duplicateSelected');

        $copy = Post::query()->where('id', '!=', $post->id)->firstOrFail();

        $this->assertSame($media->stored_path, $copy->media()->first()->stored_path);
    }

    public function test_recycling_a_published_post_opens_a_future_copy(): void
    {
        $post = $this->makePost([
            'status' => PostStatus::Published,
            'published_at' => now()->subMonth(),
        ]);

        $copy = null;

        Livewire::actingAs($this->admin)
            ->test(Posts::class)
            ->call('recycle', $post->id)
            ->assertRedirect();

        $copy = Post::query()->where('id', '!=', $post->id)->firstOrFail();

        $this->assertSame(PostStatus::Draft, $copy->status);
        $this->assertTrue($copy->scheduled_at->isFuture());
    }

    public function test_bulk_delete_respects_ownership(): void
    {
        $mine = $this->makePost(['created_by' => $this->writer->id]);
        $theirs = $this->makePost(['created_by' => $this->admin->id]);

        Livewire::actingAs($this->writer)
            ->test(Posts::class)
            ->set('selected', [$mine->id, $theirs->id])
            ->call('deleteSelected');

        // Posts soft-delete, so this is assertSoftDeleted rather than a null
        // check: fresh() bypasses global scopes and would return the row either
        // way.
        $this->assertSoftDeleted($mine);
        $this->assertNotSoftDeleted($theirs);

        // And the scoped query no longer surfaces the deleted one.
        $this->assertNull(Post::query()->find($mine->id));
        $this->assertNotNull(Post::query()->find($theirs->id));
    }

    public function test_selecting_the_page_selects_the_filtered_set(): void
    {
        $this->makePost(['status' => PostStatus::Draft]);
        $this->makePost(['status' => PostStatus::Draft]);
        $this->makePost(['status' => PostStatus::Scheduled]);

        $component = Livewire::actingAs($this->admin)
            ->test(Posts::class)
            ->set('status', 'draft')
            ->set('selectPage', true);

        $this->assertCount(2, $component->get('selected'), 'Only the filtered rows should be selected.');
    }

    public function test_changing_a_filter_clears_the_selection(): void
    {
        $post = $this->makePost();

        Livewire::actingAs($this->admin)
            ->test(Posts::class)
            ->set('selected', [$post->id])
            ->set('search', 'something')
            ->assertSet('selected', []);
    }
}
