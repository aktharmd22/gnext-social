<?php

declare(strict_types=1);

namespace App\Services\Meta\Publishers;

use App\Enums\PostType;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Meta\GraphError;
use App\Services\Meta\GraphException;
use App\Services\Meta\MetaClient;
use RuntimeException;

/**
 * Publishing to a Facebook Page.
 *
 * Simpler than Instagram in one important way: the Page endpoints accept a
 * media URL and publish in a single call. Facebook also supports genuine
 * server-side scheduling via scheduled_publish_time, but we do not use it --
 * see publish() for why.
 */
class FacebookPublisher implements Publisher
{
    public function __construct(private readonly MetaClient $client) {}

    public function publish(PostTarget $target, string $caption): PublishResult
    {
        $account = $target->socialAccount;
        $post = $target->post;
        $pageId = $account->graphNodeId();

        if ($pageId === null) {
            throw new GraphException(GraphError::fromTransport('This account has no Facebook Page id.'));
        }

        $token = $account->access_token;
        $media = $post->media->where('status', \App\Enums\MediaStatus::Ready)->values();

        /*
         * Facebook would happily take scheduled_publish_time and publish this
         * on its own. We publish at the moment instead, deliberately: Instagram
         * has no equivalent, so the worker already owns the clock. Letting the
         * two platforms be scheduled by different mechanisms would mean a post
         * could go out on Facebook while its Instagram sibling is still stuck
         * behind a failed media fetch -- a split we could not cancel.
         */
        $result = match (true) {
            $media->isEmpty() => $this->publishText($pageId, $caption, $token),
            $post->type === PostType::Reel => $this->publishVideo($pageId, $caption, $media->first(), $token),
            $media->count() > 1 => $this->publishCarousel($pageId, $caption, $media, $token),
            $media->first()->isVideo() => $this->publishVideo($pageId, $caption, $media->first(), $token),
            default => $this->publishPhoto($pageId, $caption, $media->first(), $token),
        };

        return new PublishResult(
            externalId: $result,
            permalink: $this->permalink($result, $token),
        );
    }

    /**
     * @return string the created post id
     */
    private function publishText(string $pageId, string $caption, string $token): string
    {
        $response = $this->client->post("{$pageId}/feed", [
            'message' => $caption,
        ], $token);

        return (string) ($response['id'] ?? throw new RuntimeException('Facebook did not return a post id.'));
    }

    private function publishPhoto(string $pageId, string $caption, PostMedia $media, string $token): string
    {
        $response = $this->client->post("{$pageId}/photos", [
            'url' => $media->public_url,
            'caption' => $caption,
            'published' => 'true',
        ], $token);

        // /photos returns the photo id plus, usually, the resulting post id.
        return (string) ($response['post_id']
            ?? $response['id']
            ?? throw new RuntimeException('Facebook did not return a post id.'));
    }

    private function publishVideo(string $pageId, string $caption, PostMedia $media, string $token): string
    {
        $response = $this->client->post("{$pageId}/videos", [
            'file_url' => $media->public_url,
            'description' => $caption,
        ], $token);

        return (string) ($response['id'] ?? throw new RuntimeException('Facebook did not return a video id.'));
    }

    /**
     * Multi-photo posts: upload each photo unpublished, then attach them all to
     * one feed story.
     *
     * @param  \Illuminate\Support\Collection<int, PostMedia>  $media
     */
    private function publishCarousel(string $pageId, string $caption, $media, string $token): string
    {
        $attached = [];

        foreach ($media as $item) {
            $uploaded = $this->client->post("{$pageId}/photos", [
                'url' => $item->public_url,
                // Unpublished: these exist only to be attached below.
                'published' => 'false',
            ], $token);

            if (isset($uploaded['id'])) {
                $attached[] = ['media_fbid' => (string) $uploaded['id']];
            }
        }

        if ($attached === []) {
            throw new RuntimeException('None of the images could be uploaded to Facebook.');
        }

        $response = $this->client->post("{$pageId}/feed", [
            'message' => $caption,
            'attached_media' => json_encode($attached, JSON_THROW_ON_ERROR),
        ], $token);

        return (string) ($response['id'] ?? throw new RuntimeException('Facebook did not return a post id.'));
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

    /**
     * Best effort. A missing permalink is a cosmetic loss, not a failed post,
     * so it must never turn a success into a failure.
     */
    private function permalink(string $postId, string $token): ?string
    {
        try {
            $response = $this->client->get($postId, ['fields' => 'permalink_url'], $token);

            return isset($response['permalink_url']) ? (string) $response['permalink_url'] : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
