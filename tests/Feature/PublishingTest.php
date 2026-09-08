<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostType;
use App\Models\AppCredential;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Meta\GraphException;
use App\Services\Meta\MetaClient;
use App\Services\Meta\Publishers\FacebookPublisher;
use App\Services\Meta\Publishers\InstagramPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Publishing, against a faked Graph API.
 *
 * Instagram has no scheduling endpoint, so every post is create-container ->
 * poll -> publish. These tests pin that sequence, its polling, and the way its
 * failures are explained, without needing a reviewed Meta app.
 */
class PublishingTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();

        AppCredential::factory()->create([
            'workspace_id' => $this->workspace->id,
            'meta_app_secret' => 'the-app-secret',
            'graph_version' => 'v21.0',
        ]);
    }

    private function target(SocialAccount $account, PostType $type = PostType::Post, int $mediaCount = 1): PostTarget
    {
        $post = Post::factory()->create([
            'workspace_id' => $this->workspace->id,
            'type' => $type,
            'caption' => 'Hello Dubai',
        ]);

        for ($i = 0; $i < $mediaCount; $i++) {
            $factory = PostMedia::factory();

            if ($type === PostType::Reel) {
                $factory = $factory->reel();
            }

            $factory->create([
                'post_id' => $post->id,
                'position' => $i,
                'public_url' => 'https://gnext.test/storage/media/file-'.$i.($type === PostType::Reel ? '.mp4' : '.jpg'),
            ]);
        }

        return PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
        ]);
    }

    private function client(): MetaClient
    {
        return new MetaClient(
            AppCredential::withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->firstOrFail()
        );
    }

    private function instagram(): InstagramPublisher
    {
        // Exercise the polling logic without spending real seconds on it.
        return (new InstagramPublisher($this->client()))->sleepUsing(fn (int $s) => null);
    }

    // ---------------------------------------------------------------- Facebook

    public function test_a_facebook_photo_post_is_a_single_call(): void
    {
        $account = SocialAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'page_id' => '1122334455',
        ]);

        Http::fake([
            '*/1122334455/photos' => Http::response(['id' => 'photo-1', 'post_id' => '1122334455_999']),
            '*/1122334455_999*' => Http::response(['permalink_url' => 'https://facebook.com/999']),
        ]);

        $result = (new FacebookPublisher($this->client()))
            ->publish($this->target($account), 'Hello Dubai');

        $this->assertSame('1122334455_999', $result->externalId);
        $this->assertSame('https://facebook.com/999', $result->permalink);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v21.0/1122334455/photos')
            && $r['caption'] === 'Hello Dubai'
            && $r['url'] === 'https://gnext.test/storage/media/file-0.jpg');
    }

    public function test_a_facebook_carousel_uploads_children_unpublished_then_attaches_them(): void
    {
        $account = SocialAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'page_id' => '1122334455',
        ]);

        $uploaded = 0;

        Http::fake(function (Request $request) use (&$uploaded) {
            if (str_contains($request->url(), '/photos')) {
                $uploaded++;

                return Http::response(['id' => 'photo-'.$uploaded]);
            }

            if (str_contains($request->url(), '/feed')) {
                return Http::response(['id' => 'post-carousel']);
            }

            return Http::response(['permalink_url' => 'https://facebook.com/carousel']);
        });

        $result = (new FacebookPublisher($this->client()))
            ->publish($this->target($account, PostType::Carousel, 3), 'Three things');

        $this->assertSame('post-carousel', $result->externalId);
        $this->assertSame(3, $uploaded);

        // Children must be uploaded unpublished, or three loose photos appear
        // on the page alongside the carousel.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/photos') && $r['published'] === 'false');
    }

    // --------------------------------------------------------------- Instagram

    public function test_an_instagram_image_post_creates_polls_then_publishes(): void
    {
        $account = SocialAccount::factory()->instagram()->create([
            'workspace_id' => $this->workspace->id,
            'ig_user_id' => '17841400000000000',
        ]);

        $calls = [];

        Http::fake(function (Request $request) use (&$calls) {
            $calls[] = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_contains($request->url(), '/media_publish') => Http::response(['id' => 'ig-media-1']),
                str_contains($request->url(), '/media') && $request->method() === 'POST' => Http::response(['id' => 'container-1']),
                str_contains($request->url(), 'status_code') => Http::response(['status_code' => 'FINISHED']),
                default => Http::response(['permalink' => 'https://instagram.com/p/abc']),
            };
        });

        $target = $this->target($account);
        $result = $this->instagram()->publish($target, 'Hello Dubai');

        $this->assertSame('ig-media-1', $result->externalId);
        $this->assertSame('container-1', $result->containerId);
        $this->assertSame('https://instagram.com/p/abc', $result->permalink);

        // The three steps, in order, every time.
        $this->assertSame('POST /v21.0/17841400000000000/media', $calls[0]);
        $this->assertSame('GET /v21.0/container-1', $calls[1]);
        $this->assertSame('POST /v21.0/17841400000000000/media_publish', $calls[2]);

        // The container id is persisted before publishing, so a crash between
        // the two does not orphan the upload.
        $this->assertSame('container-1', $target->fresh()->container_id);
    }

    public function test_it_waits_while_instagram_is_still_processing(): void
    {
        $account = SocialAccount::factory()->instagram()->create([
            'workspace_id' => $this->workspace->id,
            'ig_user_id' => '17841400000000000',
        ]);

        $polls = 0;

        Http::fake(function (Request $request) use (&$polls) {
            if (str_contains($request->url(), '/media_publish')) {
                return Http::response(['id' => 'ig-reel-1']);
            }

            if (str_contains($request->url(), '/media') && $request->method() === 'POST') {
                return Http::response(['id' => 'container-reel']);
            }

            if (str_contains($request->url(), 'status_code')) {
                $polls++;

                // Video containers routinely take 30s+ to finish.
                return Http::response(['status_code' => $polls < 3 ? 'IN_PROGRESS' : 'FINISHED']);
            }

            return Http::response(['permalink' => 'https://instagram.com/reel/xyz']);
        });

        $result = $this->instagram()->publish($this->target($account, PostType::Reel), 'A reel');

        $this->assertSame('ig-reel-1', $result->externalId);
        $this->assertSame(3, $polls, 'Expected polling to continue until FINISHED.');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/media')
            && $r->method() === 'POST'
            && ($r['media_type'] ?? null) === 'REELS'
            && str_ends_with((string) ($r['video_url'] ?? ''), '.mp4'));
    }

    public function test_a_rejected_container_explains_itself_in_plain_language(): void
    {
        $account = SocialAccount::factory()->instagram()->create([
            'workspace_id' => $this->workspace->id,
            'ig_user_id' => '17841400000000000',
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/media') && $request->method() === 'POST') {
                return Http::response(['id' => 'container-bad']);
            }

            return Http::response([
                'status_code' => 'ERROR',
                'status' => 'The media aspect ratio is not supported.',
            ]);
        });

        try {
            $this->instagram()->publish($this->target($account, PostType::Reel), 'A reel');
            $this->fail('Expected a GraphException.');
        } catch (GraphException $exception) {
            $message = $exception->error->message;

            $this->assertStringContainsString('aspect ratio', $message);
            $this->assertStringContainsString('9:16', $message);

            // A rejected format will never succeed, so it must not be retried.
            $this->assertFalse($exception->isRetryable());
        }
    }

    public function test_polling_gives_up_and_says_what_it_was_waiting_on(): void
    {
        config(['gnext.publishing.container_poll_timeout' => 10]);

        $account = SocialAccount::factory()->instagram()->create([
            'workspace_id' => $this->workspace->id,
            'ig_user_id' => '17841400000000000',
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/media') && $request->method() === 'POST') {
                return Http::response(['id' => 'container-slow']);
            }

            return Http::response(['status_code' => 'IN_PROGRESS']);
        });

        try {
            $this->instagram()->publish($this->target($account, PostType::Reel), 'A reel');
            $this->fail('Expected a GraphException.');
        } catch (GraphException $exception) {
            $this->assertStringContainsString('still processing', $exception->error->message);

            // A slow encode might well succeed next time, so this one retries.
            $this->assertTrue($exception->isRetryable());
        }
    }

    public function test_an_instagram_carousel_polls_every_child_before_the_parent(): void
    {
        $account = SocialAccount::factory()->instagram()->create([
            'workspace_id' => $this->workspace->id,
            'ig_user_id' => '17841400000000000',
        ]);

        $containers = 0;
        $polled = [];

        Http::fake(function (Request $request) use (&$containers, &$polled) {
            if (str_contains($request->url(), '/media_publish')) {
                return Http::response(['id' => 'ig-carousel']);
            }

            if (str_contains($request->url(), '/media') && $request->method() === 'POST') {
                $containers++;

                return Http::response(['id' => 'c'.$containers]);
            }

            if (str_contains($request->url(), 'status_code')) {
                preg_match('#/v21\.0/([^?]+)#', $request->url(), $m);
                $polled[] = $m[1] ?? '';

                return Http::response(['status_code' => 'FINISHED']);
            }

            return Http::response(['permalink' => 'https://instagram.com/p/carousel']);
        });

        $result = $this->instagram()->publish($this->target($account, PostType::Carousel, 3), 'Three things');

        $this->assertSame('ig-carousel', $result->externalId);

        // Three children plus the parent container.
        $this->assertSame(4, $containers);
        $this->assertSame(['c1', 'c2', 'c3', 'c4'], $polled);

        Http::assertSent(fn (Request $r) => ($r['media_type'] ?? null) === 'CAROUSEL'
            && ($r['children'] ?? null) === 'c1,c2,c3');
    }

    public function test_the_first_comment_is_posted_after_publication(): void
    {
        $account = SocialAccount::factory()->instagram()->create([
            'workspace_id' => $this->workspace->id,
            'ig_user_id' => '17841400000000000',
        ]);

        Http::fake(['*' => Http::response(['id' => 'comment-1'])]);

        $target = $this->target($account);
        $target->forceFill(['external_id' => 'ig-media-1'])->save();

        $commentId = $this->instagram()->postFirstComment($target, '#dubai #uae');

        $this->assertSame('comment-1', $commentId);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/ig-media-1/comments')
            && $r['message'] === '#dubai #uae');
    }

    // ------------------------------------------------------------ credentials

    public function test_every_call_is_bound_to_the_app_with_an_appsecret_proof(): void
    {
        $account = SocialAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'page_id' => '1122334455',
            'access_token' => 'page-token-xyz',
        ]);

        Http::fake(['*' => Http::response(['id' => 'x', 'post_id' => 'p'])]);

        (new FacebookPublisher($this->client()))->publish($this->target($account), 'Hi');

        $expected = hash_hmac('sha256', 'page-token-xyz', 'the-app-secret');

        Http::assertSent(fn (Request $r) => ($r['appsecret_proof'] ?? null) === $expected);
    }

    /**
     * publish_logs is the table most likely to be read while debugging, which
     * makes it the worst possible place for an access token to sit.
     */
    public function test_a_recorded_exchange_never_contains_a_token(): void
    {
        $account = SocialAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'page_id' => '1122334455',
            'access_token' => 'super-secret-page-token',
        ]);

        Http::fake(['*' => Http::response(['id' => 'x', 'post_id' => 'p'])]);

        $exchanges = [];
        $client = $this->client()->recordUsing(function ($exchange) use (&$exchanges) {
            $exchanges[] = $exchange;
        });

        (new FacebookPublisher($client))->publish($this->target($account), 'Hi');

        $this->assertNotEmpty($exchanges);

        foreach ($exchanges as $exchange) {
            $encoded = json_encode($exchange->requestPayload);

            $this->assertStringNotContainsString('super-secret-page-token', (string) $encoded);
            $this->assertStringNotContainsString('the-app-secret', (string) $encoded);

            if (array_key_exists('access_token', $exchange->requestPayload)) {
                $this->assertSame('[redacted]', $exchange->requestPayload['access_token']);
            }
        }
    }

    /**
     * debug_token carries the token under inspection as `input_token`, and an
     * app token is literally "app_id|app_secret". Redacting only `access_token`
     * would write the app secret straight into publish_logs.
     */
    public function test_the_app_secret_is_redacted_when_inspecting_a_token(): void
    {
        Http::fake(['*' => Http::response(['data' => ['is_valid' => true, 'scopes' => []]])]);

        $exchanges = [];
        $client = $this->client()->recordUsing(function ($exchange) use (&$exchanges) {
            $exchanges[] = $exchange;
        });

        $credential = AppCredential::withoutGlobalScopes()
            ->where('workspace_id', $this->workspace->id)
            ->firstOrFail();

        $client->get('debug_token', [
            'input_token' => $credential->meta_app_id.'|'.$credential->meta_app_secret,
            'access_token' => $credential->meta_app_id.'|'.$credential->meta_app_secret,
        ]);

        $this->assertNotEmpty($exchanges);

        foreach ($exchanges as $exchange) {
            $encoded = (string) json_encode($exchange->requestPayload);

            $this->assertStringNotContainsString('the-app-secret', $encoded);
            $this->assertSame('[redacted]', $exchange->requestPayload['input_token']);
        }
    }

    public function test_a_dead_token_is_reported_as_terminal_and_names_the_account(): void
    {
        $account = SocialAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'page_id' => '1122334455',
            'name' => 'Spark Tires',
        ]);

        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Error validating access token: Session has expired.',
                    'type' => 'OAuthException',
                    'code' => 190,
                    'error_subcode' => 463,
                ],
            ], 400),
        ]);

        try {
            (new FacebookPublisher($this->client()))->publish($this->target($account), 'Hi');
            $this->fail('Expected a GraphException.');
        } catch (GraphException $exception) {
            $this->assertFalse($exception->isRetryable());
            $this->assertTrue($exception->error->isTokenProblem());
            $this->assertStringContainsString('Spark Tires', $exception->userMessage('Spark Tires'));
            $this->assertStringContainsString('Reconnect', $exception->userMessage('Spark Tires'));
        }
    }
}
