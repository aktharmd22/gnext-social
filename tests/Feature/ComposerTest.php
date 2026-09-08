<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MediaSourceType;
use App\Enums\MediaStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Jobs\FetchMediaJob;
use App\Livewire\Composer;
use App\Models\CaptionTemplate;
use App\Models\HashtagSet;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ComposerTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    private User $writer;

    private SocialAccount $facebook;

    private SocialAccount $instagram;

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

        $this->facebook = SocialAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Spark Tires',
        ]);

        $this->instagram = SocialAccount::factory()->instagram()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Spark Tires',
        ]);
    }

    /**
     * Attach media and mark it fetched, as FetchMediaJob would.
     *
     * Needed wherever Instagram is a destination: Instagram cannot publish
     * without media, so a text-only post is legitimately blocked there.
     */
    private function attachReadyMedia($component): void
    {
        $component->set('mediaUrl', 'https://cdn.example.test/a.jpg')->call('addMedia');

        PostMedia::query()->update([
            'status' => MediaStatus::Ready,
            'mime' => 'image/jpeg',
            'width' => 1080,
            'height' => 1080,
            'public_url' => 'https://gnext.test/storage/a.jpg',
        ]);
    }

    // ================================================================ opening

    public function test_opening_a_new_post_preselects_every_connected_account(): void
    {
        Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew')
            ->assertSet('open', true)
            ->assertSet('destinations', [$this->facebook->id, $this->instagram->id]);
    }

    public function test_opening_on_a_specific_day_uses_that_date(): void
    {
        Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew', '2026-10-14', '18:30')
            ->assertSet('scheduledDate', '2026-10-14')
            ->assertSet('scheduledTime', '18:30');
    }

    // ================================================================ saving

    public function test_an_admin_scheduling_a_post_creates_a_target_per_destination(): void
    {
        Queue::fake();

        $component = Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew', '2026-10-14', '09:00')
            ->set('title', 'Back-to-school range')
            ->set('caption', 'The new range is in store now.');

        $this->attachReadyMedia($component);

        $component->call('schedule')->assertHasNoErrors();

        $post = Post::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(PostStatus::Scheduled, $post->status);
        $this->assertSame('Back-to-school range', $post->title);
        $this->assertSame(2, $post->targets()->count());

        // Stored UTC; 09:00 Dubai is 05:00 UTC.
        $this->assertSame('05:00', $post->scheduled_at->format('H:i'));
        $this->assertSame(
            '09:00',
            $post->scheduled_at->copy()->setTimezone('Asia/Dubai')->format('H:i')
        );
    }

    /**
     * A user's work goes through approval. Only an admin puts something
     * straight into the queue.
     */
    public function test_a_writer_scheduling_a_post_sends_it_for_approval_instead(): void
    {
        Queue::fake();

        $component = Livewire::actingAs($this->writer)
            ->test(Composer::class)
            ->call('openNew', '2026-10-14', '09:00')
            ->set('caption', 'A caption written by a non-admin.');

        $this->attachReadyMedia($component);

        $component->call('schedule')->assertHasNoErrors();

        $this->assertSame(
            PostStatus::PendingApproval,
            Post::withoutGlobalScopes()->firstOrFail()->status
        );
    }

    public function test_scheduling_without_a_caption_is_refused(): void
    {
        Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew')
            ->set('caption', '')
            ->call('schedule')
            ->assertHasErrors('caption');

        $this->assertSame(0, Post::withoutGlobalScopes()->count());
    }

    public function test_scheduling_without_a_destination_is_refused(): void
    {
        Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew')
            ->set('caption', 'Something worth publishing.')
            ->set('destinations', [])
            ->call('schedule')
            ->assertHasErrors('destinations');
    }

    public function test_a_draft_saves_without_any_of_that(): void
    {
        Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew')
            ->set('title', 'Half an idea')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertSame(PostStatus::Draft, Post::withoutGlobalScopes()->firstOrFail()->status);
    }

    public function test_editing_loads_the_post_back_into_the_form(): void
    {
        $post = Post::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->admin->id,
            'title' => 'Existing',
            'caption' => 'Existing caption',
            'type' => PostType::Reel,
            'scheduled_at' => \Illuminate\Support\Carbon::parse('2026-10-14 19:30', 'Asia/Dubai')->utc(),
        ]);

        PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $this->instagram->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openExisting', $post->id)
            ->assertSet('title', 'Existing')
            ->assertSet('caption', 'Existing caption')
            ->assertSet('type', 'reel')
            ->assertSet('scheduledDate', '2026-10-14')
            ->assertSet('scheduledTime', '19:30')
            ->assertSet('destinations', [$this->instagram->id]);
    }

    /**
     * Removing a destination must never delete a row that has already
     * published: that row holds the permalink.
     */
    public function test_deselecting_a_destination_leaves_published_targets_alone(): void
    {
        $post = Post::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->admin->id,
            'caption' => 'Already out there.',
        ]);

        $published = PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $this->facebook->id,
            'status' => \App\Enums\TargetStatus::Published,
            'permalink' => 'https://facebook.com/999',
        ]);

        $queued = PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $this->instagram->id,
            'status' => \App\Enums\TargetStatus::Queued,
        ]);

        Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openExisting', $post->id)
            ->set('destinations', [])
            ->call('saveDraft');

        $this->assertNotNull($published->fresh(), 'A published target must survive.');
        $this->assertNull($queued->fresh(), 'A queued target should be removed.');
    }

    // ============================================================== captions

    public function test_the_brand_footer_is_appended_to_the_effective_caption(): void
    {
        CaptionTemplate::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Brand footer',
            'body' => '📞 +971 4 555 0132 | 🌐 gnextsocial.ae | 📍 Dubai',
            'is_footer' => true,
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew')
            ->set('caption', 'The new range is in.')
            ->set('appendBrandFooter', true);

        $caption = $component->instance()->effectiveCaption(Platform::Instagram);

        $this->assertStringContainsString('The new range is in.', $caption);
        $this->assertStringContainsString('gnextsocial.ae', $caption);
    }

    public function test_the_footer_is_not_appended_twice(): void
    {
        CaptionTemplate::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Brand footer',
            'body' => 'Call us on 800-TIRES',
            'is_footer' => true,
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew')
            // An imported caption often already carries its own footer.
            ->set('caption', "New range in store.\n\nCall us on 800-TIRES");

        $caption = $component->instance()->effectiveCaption(Platform::Instagram);

        $this->assertSame(1, substr_count($caption, 'Call us on 800-TIRES'));
    }

    public function test_a_per_platform_override_splits_the_caption(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew')
            ->set('caption', 'Shared prose.')
            ->set('appendBrandFooter', false)
            ->set('splitCaptions', true)
            ->set('captionInstagram', 'Shared prose. #dubai #uae');

        $this->assertSame('Shared prose. #dubai #uae', $component->instance()->effectiveCaption(Platform::Instagram));

        // Facebook falls back to the shared caption when its override is blank.
        $this->assertSame('Shared prose.', $component->instance()->effectiveCaption(Platform::Facebook));
    }

    public function test_the_override_is_persisted_and_reloaded(): void
    {
        Queue::fake();

        $component = Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew', '2026-10-14', '09:00')
            ->set('caption', 'Clean prose for Facebook.')
            ->set('splitCaptions', true)
            ->set('captionInstagram', 'Clean prose. #dubai');

        $this->attachReadyMedia($component);

        $component->call('schedule')->assertHasNoErrors();

        $post = Post::withoutGlobalScopes()->with('overrides')->firstOrFail();

        $this->assertSame(1, $post->overrides->count());
        $this->assertSame('Clean prose. #dubai', $post->overrides->first()->caption);
        $this->assertSame(Platform::Instagram, $post->overrides->first()->platform);
    }

    // ============================================================== counters

    public function test_counters_report_length_hashtags_and_mentions_per_platform(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew')
            ->set('appendBrandFooter', false)
            ->set('caption', 'Hello Dubai #dubai #uae @sparktires');

        $counters = $component->instance()->counters;

        $this->assertSame(2, $counters['instagram']['hashtags']);
        $this->assertSame(1, $counters['instagram']['mentions']);
        $this->assertSame(2200, $counters['instagram']['limit']);
        $this->assertSame('fine', $counters['instagram']['state']);

        // Facebook has no hashtag or mention cap.
        $this->assertNull($counters['facebook']['hashtagLimit']);
    }

    public function test_a_caption_past_the_instagram_limit_reports_as_over(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew')
            ->set('appendBrandFooter', false)
            ->set('caption', str_repeat('a', 2400));

        $this->assertSame('over', $component->instance()->counters['instagram']['state']);
        $this->assertSame('fine', $component->instance()->counters['facebook']['state']);
    }

    public function test_thirty_one_hashtags_reports_as_over_on_instagram(): void
    {
        $caption = 'Great day '.implode(' ', array_map(fn ($i) => '#tag'.$i, range(1, 31)));

        $component = Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew')
            ->set('appendBrandFooter', false)
            ->set('caption', $caption);

        $this->assertSame('over', $component->instance()->counters['instagram']['state']);
    }

    // ================================================================= media

    public function test_pasting_a_drive_link_queues_a_fetch(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew')
            ->set('caption', 'With media.')
            ->set('mediaUrl', 'https://drive.google.com/file/d/1A2b3C4d5E6f7G8h/view?usp=drive_link')
            ->call('addMedia')
            ->assertHasNoErrors();

        $media = PostMedia::query()->firstOrFail();

        $this->assertSame(MediaSourceType::Drive, $media->source_type);
        $this->assertSame(MediaStatus::Pending, $media->status);

        Queue::assertPushed(FetchMediaJob::class);
    }

    public function test_a_drive_folder_link_is_refused_before_anything_is_queued(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew')
            ->set('mediaUrl', 'https://drive.google.com/drive/folders/1A2b3C4d5E6f7G8h')
            ->call('addMedia')
            ->assertHasErrors('mediaUrl');

        Queue::assertNothingPushed();
        $this->assertSame(0, PostMedia::query()->count());
    }

    /**
     * A post must never reach Scheduled with media that has not been fetched:
     * Meta reads the URL itself, so an unresolved link is a guaranteed failure
     * at the scheduled minute.
     */
    public function test_a_post_cannot_be_scheduled_while_media_is_still_fetching(): void
    {
        Queue::fake();

        $component = Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew', '2026-10-14', '09:00')
            ->set('caption', 'Waiting on media.')
            ->set('mediaUrl', 'https://cdn.example.test/a.jpg')
            ->call('addMedia')
            ->call('schedule');

        $component->assertHasErrors('blocking');

        $this->assertSame(
            PostStatus::Draft,
            Post::withoutGlobalScopes()->firstOrFail()->status,
            'The post should still be a draft, not scheduled.'
        );
    }

    public function test_media_that_fails_platform_rules_blocks_scheduling(): void
    {
        $post = Post::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->admin->id,
            'caption' => 'A square reel.',
            'type' => PostType::Reel,
        ]);

        PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $this->instagram->id,
        ]);

        // 1:1 video attached to a Reel: Instagram needs 9:16.
        PostMedia::factory()->create([
            'post_id' => $post->id,
            'mime' => 'video/mp4',
            'width' => 1080,
            'height' => 1080,
            'duration_seconds' => 20.0,
            'status' => MediaStatus::Ready,
        ]);

        Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openExisting', $post->id)
            ->call('schedule')
            ->assertHasErrors('blocking')
            ->assertSee('9:16');
    }

    // ============================================================= templates

    public function test_a_hashtag_set_is_inserted_in_one_click(): void
    {
        $set = HashtagSet::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'UAE general',
            'tags' => ['dubai', 'uae', 'mydubai'],
        ]);

        Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew')
            ->set('caption', 'New range in store.')
            ->call('insertHashtagSet', $set->id)
            ->assertSet('caption', "New range in store.\n\n#dubai #uae #mydubai");
    }

    public function test_a_caption_template_is_inserted_in_one_click(): void
    {
        $template = CaptionTemplate::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Weekend offer',
            'body' => 'This weekend only.',
            'is_footer' => false,
        ]);

        Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew')
            ->call('insertTemplate', $template->id)
            ->assertSet('caption', 'This weekend only.');
    }

    // ============================================================= duplicates

    public function test_a_near_identical_caption_is_flagged_against_recent_history(): void
    {
        $caption = 'Our back-to-school range has landed in every branch across Dubai and Sharjah. '
            .'Notebooks, bags and everything else on the list.';

        Post::factory()->published()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->admin->id,
            'title' => 'Back to school launch',
            'caption' => $caption,
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew')
            ->set('caption', $caption.' Come in this weekend.');

        $duplicate = $component->instance()->duplicate;

        $this->assertNotNull($duplicate);
        $this->assertGreaterThanOrEqual(80, $duplicate['similarity']);
        $this->assertSame('Back to school launch', $duplicate['post']->title);
    }

    public function test_a_different_caption_is_not_flagged(): void
    {
        Post::factory()->published()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->admin->id,
            'caption' => 'Our back-to-school range has landed in every branch across Dubai and Sharjah.',
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew')
            ->set('caption', 'Winter tyres are now half price for the whole of December, while stocks last.');

        $this->assertNull($component->instance()->duplicate);
    }

    /**
     * Facebook publishes a text-only feed post perfectly happily. Instagram
     * cannot publish anything without media at all.
     */
    public function test_a_text_only_post_is_allowed_on_facebook_alone(): void
    {
        Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew', '2026-10-14', '09:00')
            ->set('caption', 'A short announcement, no image needed.')
            ->set('destinations', [$this->facebook->id])
            ->call('schedule')
            ->assertHasNoErrors();

        $this->assertSame(PostStatus::Scheduled, Post::withoutGlobalScopes()->firstOrFail()->status);
    }

    public function test_a_text_only_post_is_blocked_when_instagram_is_a_destination(): void
    {
        Livewire::actingAs($this->admin)
            ->test(Composer::class)
            ->call('openNew', '2026-10-14', '09:00')
            ->set('caption', 'A short announcement, no image needed.')
            ->set('destinations', [$this->instagram->id])
            ->call('schedule')
            ->assertHasErrors('blocking')
            ->assertSee('Instagram cannot publish without');
    }

    // ================================================================ access

    public function test_a_writer_cannot_open_someone_elses_post(): void
    {
        $post = Post::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->admin->id,
        ]);

        Livewire::actingAs($this->writer)
            ->test(Composer::class)
            ->call('openExisting', $post->id)
            ->assertForbidden();
    }
}
