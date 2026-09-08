<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\MediaStatus;
use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Jobs\PublishPostTargetJob;
use App\Models\PostTarget;
use App\Models\PublishLog;
use App\Services\CaptionComposer;
use App\Services\Meta\GraphError;
use App\Services\Meta\GraphException;
use App\Services\Meta\Publishers\PublisherFactory;
use Throwable;

/**
 * One publish attempt against one destination, from guard checks to logging.
 *
 * Deliberately per-target, never per-post: a failure at Instagram must not stop
 * the Facebook sibling, and a retry of one must not republish the other.
 */
class PublishOrchestrator
{
    public function __construct(
        private readonly PublisherFactory $publishers = new PublisherFactory,
        private readonly CaptionComposer $captions = new CaptionComposer,
        private readonly RateLimitGuard $rateLimits = new RateLimitGuard,
        private readonly PostStatusDeriver $statuses = new PostStatusDeriver,
    ) {}

    /**
     * @return bool whether the target ended up published
     */
    public function publish(PostTarget $target): bool
    {
        $target->loadMissing(['post.media', 'post.overrides', 'socialAccount']);

        if ($guard = $this->blockedReason($target)) {
            $this->fail($target, $guard['code'], $guard['message'], retryable: $guard['retryable']);

            return false;
        }

        $account = $target->socialAccount;
        $post = $target->post;

        $target->forceFill([
            'status' => TargetStatus::Publishing,
            'attempts' => $target->attempts + 1,
            'last_attempt_at' => now(),
        ])->save();

        $this->statuses->sync($post->fresh(['targets']));

        $attempt = $target->attempts;
        $caption = $this->captions->compose($post, $account->platform);

        try {
            $client = $this->publishers->clientFor($account);

            // Every exchange, successful or not, lands in publish_logs with the
            // token already redacted.
            $client->recordUsing(function ($exchange) use ($target, $attempt): void {
                PublishLog::create([
                    'post_target_id' => $target->id,
                    'attempt' => $attempt,
                    'endpoint' => $exchange->endpoint,
                    'request_payload' => $exchange->requestPayload,
                    'response_body' => $exchange->responseBody,
                    'http_code' => $exchange->httpCode,
                    'duration_ms' => $exchange->durationMs,
                ]);
            });

            $publisher = $this->publishers->for($account, $client);

            $result = $publisher->publish($target, $caption);

            $target->forceFill([
                'status' => TargetStatus::Published,
                'external_id' => $result->externalId,
                'permalink' => $result->permalink,
                'container_id' => $result->containerId ?? $target->container_id,
                'error_code' => null,
                'error_message' => null,
                'published_at' => now(),
            ])->save();

            $this->postFirstComment($target, $publisher);

            $this->statuses->sync($post->fresh(['targets']));

            return true;
        } catch (GraphException $exception) {
            $this->fail(
                $target,
                $exception->error->shortCode(),
                $exception->userMessage($account->displayName()),
                retryable: $exception->isRetryable(),
                tokenProblem: $exception->error->isTokenProblem(),
            );

            return false;
        } catch (Throwable $exception) {
            // Anything unexpected is treated as retryable once: a bug in our
            // code should not permanently kill a post that Meta never saw.
            $this->fail($target, 'unexpected', $exception->getMessage(), retryable: true);

            return false;
        }
    }

    /**
     * Reasons not to even call Meta.
     *
     * @return array{code: string, message: string, retryable: bool}|null
     */
    private function blockedReason(PostTarget $target): ?array
    {
        $account = $target->socialAccount;
        $post = $target->post;

        if ($account === null || ! $account->is_active) {
            return [
                'code' => 'account_disconnected',
                'message' => 'This account has been disconnected, so the post was not sent.',
                'retryable' => false,
            ];
        }

        if ($account->tokenHasExpired()) {
            return [
                'code' => 'token_expired',
                'message' => sprintf(
                    'The access token for %s expired on %s. Reconnect the page in Settings to resume publishing.',
                    $account->displayName(),
                    $account->token_expires_at->format('j M Y')
                ),
                'retryable' => false,
            ];
        }

        $unready = $post->media->first(fn ($media) => $media->status !== MediaStatus::Ready);

        if ($unready !== null) {
            return [
                'code' => 'media_not_ready',
                'message' => 'The media for this post was never fetched successfully, so Meta had nothing to read. '
                    .($unready->error_message ?: 'Re-fetch it from the composer and try again.'),
                'retryable' => false,
            ];
        }

        if (! $this->rateLimits->allows($account)) {
            return [
                'code' => 'rate_limited',
                'message' => (string) $this->rateLimits->reason($account),
                // Genuinely worth retrying: the window clears on its own.
                'retryable' => true,
            ];
        }

        return null;
    }

    /**
     * The first comment is a nice-to-have appended to something already live.
     * Failing it must never fail the post.
     */
    private function postFirstComment(PostTarget $target, $publisher): void
    {
        $comment = $this->captions->firstComment($target->post, $target->socialAccount->platform);

        if ($comment === null) {
            return;
        }

        try {
            $publisher->postFirstComment($target, $comment);
        } catch (Throwable) {
            // Swallowed on purpose. The post is published; a missing first
            // comment is not worth marking it failed.
        }
    }

    private function fail(
        PostTarget $target,
        string $code,
        string $message,
        bool $retryable,
        bool $tokenProblem = false,
    ): void {
        $exhausted = ! $retryable || ! $target->hasAttemptsLeft();

        $target->forceFill([
            'status' => $exhausted ? TargetStatus::Failed : TargetStatus::Queued,
            'error_code' => $code,
            'error_message' => $message,
            'last_attempt_at' => now(),
        ])->save();

        if ($tokenProblem && $target->socialAccount !== null) {
            $target->socialAccount->forceFill([
                'last_error' => $message,
                'last_checked_at' => now(),
            ])->save();
        }

        $post = $target->post?->fresh(['targets']);

        if ($post !== null) {
            $this->statuses->sync($post);
        }

        if ($exhausted) {
            event(new \App\Events\PublishFailed($target->fresh(['post', 'socialAccount'])));
        }
    }

    /**
     * Re-queue a failed target and publish it now. Used by the admin-only
     * retry action.
     *
     * The job is dispatched here rather than left for gnext:dispatch-due,
     * which would never pick it up: that command selects targets whose post is
     * still Scheduled or Approved, and a post with one destination published
     * and one failed is PartiallyPublished. Leaving it to "the next dispatch"
     * reset the row to Queued and then did nothing forever -- and because the
     * row no longer read as Failed, it also vanished from the operator's
     * needs-attention list, so the silence looked like success.
     */
    public function retry(PostTarget $target): void
    {
        $target->forceFill([
            'status' => TargetStatus::Queued,
            'attempts' => 0,
            'error_code' => null,
            'error_message' => null,
        ])->save();

        $post = $target->post?->fresh(['targets']);

        if ($post !== null && $post->status === PostStatus::Failed) {
            $this->statuses->sync($post);
        }

        PublishPostTargetJob::dispatch($target->id);
    }
}
