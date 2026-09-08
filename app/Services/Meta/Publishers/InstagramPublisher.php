<?php

declare(strict_types=1);

namespace App\Services\Meta\Publishers;

use App\Enums\MediaStatus;
use App\Enums\PostType;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Meta\GraphError;
use App\Services\Meta\GraphException;
use App\Services\Meta\MetaClient;
use Closure;
use Illuminate\Support\Collection;

/**
 * Publishing to an Instagram Business account.
 *
 * Instagram has no scheduling API. None. Every post is a three-step operation
 * performed at the moment of publication:
 *
 *   1. create a media container pointing at a public URL
 *   2. poll its status_code until FINISHED (video can take 30s+)
 *   3. call media_publish with the container id
 *
 * That is why this application owns a worker and a clock, and why a missed
 * window needs a recovery command rather than a shrug.
 *
 * Carousels multiply the dance: every child is its own container, each polled,
 * then a parent container over the children, polled again, then published.
 */
class InstagramPublisher implements Publisher
{
    /** @var null|Closure(int): void */
    private ?Closure $sleeper = null;

    public function __construct(private readonly MetaClient $client) {}

    /**
     * Swap the sleep for something instant. Tests use this so that polling
     * logic is exercised without spending real seconds on it.
     *
     * @param  null|Closure(int): void  $sleeper
     */
    public function sleepUsing(?Closure $sleeper): static
    {
        $this->sleeper = $sleeper;

        return $this;
    }

    public function publish(PostTarget $target, string $caption): PublishResult
    {
        $account = $target->socialAccount;
        $igUserId = $account->graphNodeId();

        if ($igUserId === null) {
            throw new GraphException(GraphError::fromTransport(
                'This account is not linked to an Instagram Business account.'
            ));
        }

        $token = $account->access_token;
        $post = $target->post;

        $media = $post->media
            ->filter(fn (PostMedia $item) => $item->status === MediaStatus::Ready)
            ->values();

        if ($media->isEmpty()) {
            throw new GraphException(GraphError::fromTransport(
                'Instagram cannot publish a post with no media.'
            ));
        }

        $containerId = $post->type === PostType::Carousel && $media->count() > 1
            ? $this->createCarouselContainer($igUserId, $caption, $media, $token)
            : $this->createSingleContainer($igUserId, $caption, $media->first(), $post->type, $token);

        // Record the container before publishing. If the process dies between
        // these two calls, the container id is what lets us recover rather than
        // creating a duplicate.
        $target->forceFill(['container_id' => $containerId])->save();

        $this->awaitContainer($containerId, $token);

        $published = $this->client->post("{$igUserId}/media_publish", [
            'creation_id' => $containerId,
        ], $token);

        $mediaId = (string) ($published['id']
            ?? throw new GraphException(GraphError::fromTransport(
                'Instagram accepted the publish call but returned no media id.'
            )));

        return new PublishResult(
            externalId: $mediaId,
            permalink: $this->permalink($mediaId, $token),
            containerId: $containerId,
        );
    }

    private function createSingleContainer(
        string $igUserId,
        string $caption,
        PostMedia $media,
        PostType $type,
        string $token,
    ): string {
        $payload = ['caption' => $caption];

        if ($type === PostType::Reel) {
            $payload['media_type'] = 'REELS';
            $payload['video_url'] = $media->public_url;
        } elseif ($type === PostType::Story) {
            $payload['media_type'] = 'STORIES';
            $payload[$media->isVideo() ? 'video_url' : 'image_url'] = $media->public_url;
            // Stories carry no caption.
            unset($payload['caption']);
        } elseif ($media->isVideo()) {
            // A video in a feed post is published as a Reel regardless; being
            // explicit avoids Instagram guessing differently.
            $payload['media_type'] = 'REELS';
            $payload['video_url'] = $media->public_url;
        } else {
            $payload['image_url'] = $media->public_url;
        }

        $response = $this->client->post("{$igUserId}/media", $payload, $token);

        return (string) ($response['id']
            ?? throw new GraphException(GraphError::fromTransport(
                'Instagram did not return a container id.'
            )));
    }

    /**
     * @param  Collection<int, PostMedia>  $media
     */
    private function createCarouselContainer(
        string $igUserId,
        string $caption,
        Collection $media,
        string $token,
    ): string {
        $children = [];

        foreach ($media as $item) {
            $payload = ['is_carousel_item' => 'true'];

            if ($item->isVideo()) {
                $payload['media_type'] = 'VIDEO';
                $payload['video_url'] = $item->public_url;
            } else {
                $payload['image_url'] = $item->public_url;
            }

            $child = $this->client->post("{$igUserId}/media", $payload, $token);
            $childId = (string) ($child['id'] ?? '');

            if ($childId === '') {
                throw new GraphException(GraphError::fromTransport(
                    'Instagram did not return a container id for one of the carousel items.'
                ));
            }

            // Each child must finish processing before the parent will accept
            // it. Videos in particular are not instant.
            $this->awaitContainer($childId, $token);

            $children[] = $childId;
        }

        $parent = $this->client->post("{$igUserId}/media", [
            'media_type' => 'CAROUSEL',
            'caption' => $caption,
            'children' => implode(',', $children),
        ], $token);

        return (string) ($parent['id']
            ?? throw new GraphException(GraphError::fromTransport(
                'Instagram did not return a carousel container id.'
            )));
    }

    /**
     * Poll until the container is FINISHED, with backoff.
     *
     * Gives up at the configured ceiling and surfaces the real error rather
     * than a timeout, because "IN_PROGRESS for five minutes" and "ERROR:
     * unsupported format" need completely different responses from a human.
     */
    private function awaitContainer(string $containerId, string $token): void
    {
        $timeout = (int) config('gnext.publishing.container_poll_timeout', 300);
        $delay = (int) config('gnext.publishing.container_poll_initial_delay', 3);
        $maxDelay = (int) config('gnext.publishing.container_poll_max_delay', 20);

        $waited = 0;

        while (true) {
            $status = $this->client->get($containerId, [
                'fields' => 'status_code,status',
            ], $token);

            $code = strtoupper((string) ($status['status_code'] ?? ''));

            if ($code === 'FINISHED') {
                return;
            }

            if ($code === 'ERROR') {
                throw new GraphException(new GraphError(
                    code: 2207032,
                    subcode: null,
                    message: $this->explainContainerError((string) ($status['status'] ?? '')),
                ));
            }

            if ($code === 'EXPIRED') {
                throw new GraphException(new GraphError(
                    code: 2207032,
                    subcode: null,
                    message: 'Instagram expired this upload before it could be published. '
                        .'Containers last 24 hours; this one was created too long ago.',
                ));
            }

            if ($waited >= $timeout) {
                throw new GraphException(new GraphError(
                    code: null,
                    subcode: null,
                    message: sprintf(
                        'Instagram was still processing this media after %d seconds (status: %s). '
                            .'Large or long videos sometimes exceed this; the post can be retried.',
                        $timeout,
                        $code !== '' ? $code : 'unknown'
                    ),
                    type: 'transport',
                ));
            }

            $this->sleep($delay);
            $waited += $delay;
            $delay = min($delay * 2, $maxDelay);
        }
    }

    /**
     * Instagram's `status` field is prose meant for developers. Translate the
     * common ones into something a marketer can act on.
     */
    private function explainContainerError(string $status): string
    {
        $lower = strtolower($status);

        return match (true) {
            str_contains($lower, 'aspect ratio') => 'Instagram rejected the media because of its aspect ratio. '
                .'Feed posts need between 4:5 and 1.91:1; Reels need 9:16.',
            str_contains($lower, 'duration') => 'Instagram rejected the video because of its length. '
                .'Reels must be between 3 seconds and 15 minutes.',
            str_contains($lower, 'format') || str_contains($lower, 'codec') => 'Instagram rejected the video format. '
                .'It needs to be MP4 with H.264 video and AAC audio.',
            str_contains($lower, 'download') || str_contains($lower, 'fetch') || str_contains($lower, 'curl') =>
                'Instagram could not download the media from the URL we gave it. '
                    .'Re-fetch the media so it is served from a public URL.',
            $status !== '' => 'Instagram rejected this media: '.$status,
            default => 'Instagram rejected this media without saying why. Re-fetch it and try again.',
        };
    }

    public function postFirstComment(PostTarget $target, string $comment): ?string
    {
        $externalId = $target->external_id;

        if ($externalId === null) {
            return null;
        }

        $response = $this->client->post("{$externalId}/comments", [
            'message' => $comment,
        ], $target->socialAccount->access_token);

        return isset($response['id']) ? (string) $response['id'] : null;
    }

    private function permalink(string $mediaId, string $token): ?string
    {
        try {
            $response = $this->client->get($mediaId, ['fields' => 'permalink'], $token);

            return isset($response['permalink']) ? (string) $response['permalink'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function sleep(int $seconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }

        sleep($seconds);
    }
}
