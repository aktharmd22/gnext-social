<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MediaStatus;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Enums\TargetStatus;
use App\Jobs\PublishPostTargetJob;
use App\Models\AppCredential;
use App\Models\NotificationsSetting;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\PublishLog;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PublishFailedNotification;
use App\Services\Publishing\PublishOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The scheduler: the part that makes a post go out at the minute someone chose,
 * with nobody awake.
 */
class SchedulerTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private SocialAccount $facebook;

    private SocialAccount $instagram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create(['timezone' => 'Asia/Dubai']);

        User::factory()->admin()->create([
            'workspace_id' => $this->workspace->id,
            'email' => 'admin@example.test',
        ]);

        AppCredential::factory()->create([
            'workspace_id' => $this->workspace->id,
            'meta_app_secret' => 'the-app-secret',
        ]);

        NotificationsSetting::create([
            'workspace_id' => $this->workspace->id,
            'email_on_failure' => true,
        ]);

        $this->facebook = SocialAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Spark Tires',
            'page_id' => '1122334455',
        ]);

        $this->instagram = SocialAccount::factory()->instagram()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Spark Tires',
            'ig_user_id' => '17841400000000000',
        ]);
    }

    /**
     * @return array{0: Post, 1: PostTarget, 2: PostTarget}
     */
    private function duePost(?Carbon $when = null): array
    {
        $post = Post::factory()->create([
            'workspace_id' => $this->workspace->id,
            'caption' => 'The new range is in store now.',
            'type' => PostType::Post,
            'status' => PostStatus::Scheduled,
            'scheduled_at' => ($when ?? now()->subMinute()),
        ]);

        PostMedia::factory()->create([
            'post_id' => $post->id,
            'status' => MediaStatus::Ready,
            'public_url' => 'https://gnext.test/storage/a.jpg',
        ]);

        return [
            $post,
            PostTarget::factory()->create([
                'post_id' => $post->id,
                'social_account_id' => $this->facebook->id,
            ]),
            PostTarget::factory()->create([
                'post_id' => $post->id,
                'social_account_id' => $this->instagram->id,
            ]),
        ];
    }

    private function fakeSuccessfulGraph(): void
    {
        Http::fake(function (Request $request) {
            return match (true) {
                str_contains($request->url(), '/media_publish') => Http::response(['id' => 'ig-media-1']),
                str_contains($request->url(), '/media') && $request->method() === 'POST' => Http::response(['id' => 'container-1']),
                str_contains($request->url(), 'status_code') => Http::response(['status_code' => 'FINISHED']),
                str_contains($request->url(), '/photos') => Http::response(['id' => 'photo-1', 'post_id' => 'fb-post-1']),
                str_contains($request->url(), '/comments') => Http::response(['id' => 'comment-1']),
                default => Http::response(['permalink' => 'https://instagram.com/p/abc', 'permalink_url' => 'https://facebook.com/1']),
            };
        });
    }

    // =====================================================================
    // Definition of done: it publishes, unattended
    // =====================================================================

    public function test_a_due_post_publishes_to_both_platforms(): void
    {
        $this->fakeSuccessfulGraph();

        [$post, $fb, $ig] = $this->duePost();

        $orchestrator = app(PublishOrchestrator::class);

        $this->assertTrue($orchestrator->publish($fb));
        $this->assertTrue($orchestrator->publish($ig));

        $this->assertSame(TargetStatus::Published, $fb->fresh()->status);
        $this->assertSame(TargetStatus::Published, $ig->fresh()->status);

        $this->assertSame('fb-post-1', $fb->fresh()->external_id);
        $this->assertSame('ig-media-1', $ig->fresh()->external_id);

        // The post summary follows its targets.
        $this->assertSame(PostStatus::Published, $post->fresh()->status);
        $this->assertNotNull($post->fresh()->published_at);
    }

    public function test_the_dispatcher_queues_only_what_is_due(): void
    {
        Queue::fake();

        [, $dueFb, $dueIg] = $this->duePost();

        // Not due for another two hours.
        [, $laterFb] = $this->duePost(now()->addHours(2));

        $this->artisan('gnext:dispatch-due')->assertSuccessful();

        Queue::assertPushed(PublishPostTargetJob::class, 2);

        Queue::assertPushed(
            PublishPostTargetJob::class,
            fn (PublishPostTargetJob $job) => $job->postTargetId === $dueFb->id
        );

        Queue::assertNotPushed(
            PublishPostTargetJob::class,
            fn (PublishPostTargetJob $job) => $job->postTargetId === $laterFb->id
        );
    }

    /**
     * One destination failing must never stop its sibling.
     */
    public function test_a_failure_on_one_platform_does_not_block_the_other(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '17841400000000000')) {
                return Http::response([
                    'error' => ['message' => 'Media aspect ratio not supported.', 'code' => 9004],
                ], 400);
            }

            return match (true) {
                str_contains($request->url(), '/photos') => Http::response(['id' => 'p', 'post_id' => 'fb-post-1']),
                default => Http::response(['permalink_url' => 'https://facebook.com/1']),
            };
        });

        [$post, $fb, $ig] = $this->duePost();

        $orchestrator = app(PublishOrchestrator::class);
        $orchestrator->publish($fb);
        $orchestrator->publish($ig);

        $this->assertSame(TargetStatus::Published, $fb->fresh()->status);
        $this->assertSame(TargetStatus::Failed, $ig->fresh()->status);

        // The one status a single column cannot express.
        $this->assertSame(PostStatus::PartiallyPublished, $post->fresh()->status);
    }

    // =====================================================================
    // Definition of done: the worker was down for two hours
    // =====================================================================

    public function test_posts_missed_while_the_worker_was_down_are_recovered_and_marked_late(): void
    {
        Queue::fake();

        // Due two hours ago; nothing ran.
        [$post, $fb] = $this->duePost(now()->subHours(2));

        $this->artisan('gnext:recover-missed')
            ->expectsOutputToContain('Late by')
            ->assertSuccessful();

        Queue::assertPushed(
            PublishPostTargetJob::class,
            fn (PublishPostTargetJob $job) => $job->postTargetId === $fb->id
        );

        // And once it does publish, it is visibly late rather than silently
        // indistinguishable from an on-time post.
        $post->forceFill(['published_at' => now()])->save();

        $this->assertTrue($post->fresh()->wasPublishedLate());
    }

    public function test_recovery_ignores_anything_older_than_the_window(): void
    {
        Queue::fake();

        // Nine hours ago, against a six-hour window: too old to publish now.
        $this->duePost(now()->subHours(9));

        $this->artisan('gnext:recover-missed')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    // =====================================================================
    // Definition of done: an expired token is loud, not silent
    // =====================================================================

    public function test_an_expired_token_fails_terminally_and_emails_someone(): void
    {
        Notification::fake();
        Http::fake();

        $this->facebook->forceFill(['token_expires_at' => now()->subDay()])->save();

        [, $fb] = $this->duePost();

        app(PublishOrchestrator::class)->publish($fb->fresh(['post.media', 'socialAccount']));

        $fb->refresh();

        $this->assertSame(TargetStatus::Failed, $fb->status);
        $this->assertSame('token_expired', $fb->error_code);
        $this->assertStringContainsString('Reconnect', (string) $fb->error_message);

        // Never called Meta with a token we already knew was dead.
        Http::assertNothingSent();

        Notification::assertSentTo(
            User::where('email', 'admin@example.test')->first(),
            PublishFailedNotification::class
        );
    }

    public function test_a_terminal_error_does_not_burn_three_attempts(): void
    {
        Notification::fake();

        Http::fake(['*' => Http::response([
            'error' => ['message' => 'Unsupported post request.', 'code' => 100],
        ], 400)]);

        [, $fb] = $this->duePost();

        app(PublishOrchestrator::class)->publish($fb);

        $fb->refresh();

        $this->assertSame(TargetStatus::Failed, $fb->status);
        $this->assertSame(1, $fb->attempts, 'A terminal error should fail on the first attempt.');
    }

    public function test_a_retryable_error_stays_queued_until_attempts_run_out(): void
    {
        Notification::fake();

        // Rate limited: worth retrying.
        Http::fake(['*' => Http::response([
            'error' => ['message' => 'Application request limit reached.', 'code' => 4],
        ], 400)]);

        [, $fb] = $this->duePost();

        $orchestrator = app(PublishOrchestrator::class);

        $orchestrator->publish($fb);
        $this->assertSame(TargetStatus::Queued, $fb->fresh()->status, 'Attempt 1 should stay queued.');

        $orchestrator->publish($fb->fresh(['post.media', 'socialAccount']));
        $this->assertSame(TargetStatus::Queued, $fb->fresh()->status, 'Attempt 2 should stay queued.');

        $orchestrator->publish($fb->fresh(['post.media', 'socialAccount']));

        $fb->refresh();
        $this->assertSame(TargetStatus::Failed, $fb->status, 'Attempt 3 exhausts the budget.');
        $this->assertSame(3, $fb->attempts);
    }

    public function test_media_that_never_fetched_fails_before_meta_is_called(): void
    {
        Notification::fake();
        Http::fake();

        [$post, $fb] = $this->duePost();

        $post->media()->update([
            'status' => MediaStatus::Failed->value,
            'error_message' => 'We could not download this from Google Drive.',
        ]);

        app(PublishOrchestrator::class)->publish($fb->fresh(['post.media', 'socialAccount']));

        $fb->refresh();

        $this->assertSame(TargetStatus::Failed, $fb->status);
        $this->assertSame('media_not_ready', $fb->error_code);
        $this->assertStringContainsString('Google Drive', (string) $fb->error_message);

        Http::assertNothingSent();
    }

    // =====================================================================
    // Rate limiting
    // =====================================================================

    public function test_instagram_stops_at_fifty_published_posts_in_a_day(): void
    {
        Notification::fake();
        Http::fake();

        // Fill the rolling window.
        for ($i = 0; $i < 50; $i++) {
            $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);

            PostTarget::factory()->create([
                'post_id' => $post->id,
                'social_account_id' => $this->instagram->id,
                'status' => TargetStatus::Published,
                'published_at' => now()->subHours(3),
            ]);
        }

        [, , $ig] = $this->duePost();

        app(PublishOrchestrator::class)->publish($ig->fresh(['post.media', 'socialAccount']));

        $ig->refresh();

        $this->assertSame('rate_limited', $ig->error_code);
        $this->assertStringContainsString('50 posts', (string) $ig->error_message);

        // Retryable: the window clears on its own, so it stays queued.
        $this->assertSame(TargetStatus::Queued, $ig->status);

        Http::assertNothingSent();
    }

    public function test_facebook_has_no_such_cap(): void
    {
        $this->fakeSuccessfulGraph();

        for ($i = 0; $i < 60; $i++) {
            $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);

            PostTarget::factory()->create([
                'post_id' => $post->id,
                'social_account_id' => $this->facebook->id,
                'status' => TargetStatus::Published,
                'published_at' => now()->subHours(3),
            ]);
        }

        [, $fb] = $this->duePost();

        $this->assertTrue(app(PublishOrchestrator::class)->publish($fb));
    }

    // =====================================================================
    // Logging
    // =====================================================================

    public function test_every_attempt_is_logged_with_the_token_redacted(): void
    {
        $this->fakeSuccessfulGraph();

        [, $fb] = $this->duePost();

        app(PublishOrchestrator::class)->publish($fb);

        $logs = PublishLog::query()->where('post_target_id', $fb->id)->get();

        $this->assertGreaterThan(0, $logs->count());

        foreach ($logs as $log) {
            $encoded = json_encode($log->request_payload);

            $this->assertStringNotContainsString((string) $this->facebook->access_token, (string) $encoded);
            $this->assertStringNotContainsString('the-app-secret', (string) $encoded);

            if (isset($log->request_payload['access_token'])) {
                $this->assertSame('[redacted]', $log->request_payload['access_token']);
            }
        }
    }

    public function test_a_failed_attempt_is_logged_too(): void
    {
        Notification::fake();

        Http::fake(['*' => Http::response([
            'error' => ['message' => 'Nope.', 'code' => 100],
        ], 400)]);

        [, $fb] = $this->duePost();

        app(PublishOrchestrator::class)->publish($fb);

        $this->assertGreaterThan(0, PublishLog::query()->where('post_target_id', $fb->id)->count());
    }

    // =====================================================================
    // Health
    // =====================================================================

    public function test_the_health_check_reports_a_stopped_scheduler_as_failing(): void
    {
        // Due 30 minutes ago and still queued: nothing is running.
        $this->duePost(now()->subMinutes(30));

        $this->getJson('/up')
            ->assertStatus(503)
            ->assertJsonPath('status', 'fail')
            ->assertJsonPath('checks.publishing.status', 'fail')
            ->assertJsonPath('checks.publishing.overdue_by_15_minutes', 2);
    }

    public function test_the_health_check_reports_an_expired_token_as_failing(): void
    {
        $this->facebook->forceFill(['token_expires_at' => now()->subDay()])->save();

        $this->getJson('/up')
            ->assertStatus(503)
            ->assertJsonPath('checks.tokens.status', 'fail')
            ->assertJsonPath('checks.tokens.expired', 1);
    }

    public function test_a_healthy_installation_reports_ok(): void
    {
        $this->getJson('/up')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.status', 'ok');
    }

    /**
     * The health endpoint is public so an uptime monitor can reach it, so it
     * must not leak what is being published or to whom.
     */
    public function test_the_health_check_leaks_nothing_identifying(): void
    {
        $this->duePost();

        $body = $this->getJson('/up')->content();

        $this->assertStringNotContainsString('Spark Tires', $body);
        $this->assertStringNotContainsString('new range', $body);
        $this->assertStringNotContainsString((string) $this->facebook->access_token, $body);
    }
}
