<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\Platform;
use App\Enums\PostType;

/**
 * Platform rules, checked in the composer rather than at publish time.
 *
 * Meta enforces these and its errors are cryptic. Validating here is the whole
 * difference between "This is 1:1. Instagram Reels need 9:16 -- crop or pad?"
 * in front of someone who can fix it, and "(#100) Invalid parameter" at 09:00
 * with nobody awake.
 */
class SpecValidator
{
    /** Instagram feed images: 4:5 through 1.91:1. */
    private const IG_FEED_MIN_ASPECT = 0.8;

    private const IG_FEED_MAX_ASPECT = 1.91;

    private const IG_FEED_MIN_WIDTH = 320;

    private const IG_FEED_MAX_WIDTH = 1440;

    /** Reels and Stories: 9:16, with a little tolerance for odd exports. */
    private const VERTICAL_ASPECT = 0.5625;

    private const VERTICAL_TOLERANCE = 0.03;

    private const REEL_MIN_SECONDS = 3.0;

    private const REEL_MAX_SECONDS = 900.0;

    /**
     * @return list<ValidationVerdict>
     */
    public function validateMedia(ProbeResult $media, Platform $platform, PostType $type): array
    {
        if ($platform === Platform::Facebook) {
            return $this->validateForFacebook($media, $type);
        }

        return $this->validateForInstagram($media, $type);
    }

    /**
     * Facebook is forgiving: it accepts more or less anything and crops for
     * display. Only genuinely unusable files are flagged.
     *
     * @return list<ValidationVerdict>
     */
    private function validateForFacebook(ProbeResult $media, PostType $type): array
    {
        $verdicts = [];

        if ($type->isVideo() && ! $media->isVideo()) {
            $verdicts[] = ValidationVerdict::error(
                'wrong_kind',
                'This post is set to Reel but the file is an image. Change the post type, or attach a video.'
            );
        }

        if (! $type->isVideo() && $type !== PostType::Story && $media->isVideo()) {
            $verdicts[] = ValidationVerdict::warning(
                'video_in_feed_post',
                'This is a video in a feed post. Facebook will publish it, but a Reel usually performs better.'
            );
        }

        return $verdicts;
    }

    /**
     * @return list<ValidationVerdict>
     */
    private function validateForInstagram(ProbeResult $media, PostType $type): array
    {
        return match ($type) {
            PostType::Reel => $this->validateReel($media),
            PostType::Story => $this->validateStory($media),
            default => $this->validateFeedImage($media),
        };
    }

    /**
     * @return list<ValidationVerdict>
     */
    private function validateFeedImage(ProbeResult $media): array
    {
        $verdicts = [];

        if ($media->isVideo()) {
            $verdicts[] = ValidationVerdict::error(
                'video_in_feed',
                'Instagram feed posts here expect an image. Switch this post to a Reel to publish video.'
            );

            return $verdicts;
        }

        // Instagram accepts JPEG for feed publishing. PNG is converted, but
        // anything exotic is refused outright with an opaque error.
        if (! in_array($media->mime, ['image/jpeg', 'image/png'], true)) {
            $verdicts[] = ValidationVerdict::error(
                'unsupported_image_format',
                sprintf(
                    'Instagram will not accept a %s image. Export it as JPEG and re-attach it.',
                    $this->friendlyFormat($media->mime)
                ),
                'convert_jpeg'
            );
        }

        $ratio = $media->aspectRatio();

        if ($ratio !== null && ($ratio < self::IG_FEED_MIN_ASPECT || $ratio > self::IG_FEED_MAX_ASPECT)) {
            $verdicts[] = ValidationVerdict::error(
                'aspect_out_of_range',
                sprintf(
                    'This image is %s. Instagram feed posts need between 4:5 and 1.91:1. Crop it, or pad it to fit.',
                    $media->aspectLabel()
                ),
                'crop_or_pad'
            );
        }

        if ($media->width !== null && $media->width < self::IG_FEED_MIN_WIDTH) {
            $verdicts[] = ValidationVerdict::error(
                'too_narrow',
                sprintf(
                    'This image is %dpx wide. Instagram needs at least %dpx, and it will look soft below 1080px.',
                    $media->width,
                    self::IG_FEED_MIN_WIDTH
                )
            );
        }

        if ($media->width !== null && $media->width > self::IG_FEED_MAX_WIDTH) {
            $verdicts[] = ValidationVerdict::warning(
                'wider_than_needed',
                sprintf(
                    'This image is %dpx wide. Instagram downsizes anything over %dpx, so detail will be lost.',
                    $media->width,
                    self::IG_FEED_MAX_WIDTH
                ),
                'resize'
            );
        }

        return $verdicts;
    }

    /**
     * @return list<ValidationVerdict>
     */
    private function validateReel(ProbeResult $media): array
    {
        $verdicts = [];

        if (! $media->isVideo()) {
            $verdicts[] = ValidationVerdict::error(
                'image_in_reel',
                'A Reel needs a video file. Attach an MP4, or change this post to a feed post.'
            );

            return $verdicts;
        }

        if (! in_array($media->mime, ['video/mp4', 'video/quicktime'], true)) {
            $verdicts[] = ValidationVerdict::error(
                'unsupported_video_format',
                sprintf(
                    'Instagram Reels accept MP4 or MOV. This is %s.',
                    $this->friendlyFormat($media->mime)
                ),
                'reencode'
            );
        }

        $verdicts = array_merge($verdicts, $this->checkVertical($media, 'Reels'));

        if ($media->duration !== null) {
            if ($media->duration < self::REEL_MIN_SECONDS) {
                $verdicts[] = ValidationVerdict::error(
                    'too_short',
                    sprintf(
                        'This video is %.1f seconds. Instagram Reels must be at least %d seconds.',
                        $media->duration,
                        (int) self::REEL_MIN_SECONDS
                    )
                );
            }

            if ($media->duration > self::REEL_MAX_SECONDS) {
                $verdicts[] = ValidationVerdict::error(
                    'too_long',
                    sprintf(
                        'This video is %d minutes. Instagram Reels cap at 15 minutes.',
                        (int) round($media->duration / 60)
                    ),
                    'trim'
                );
            }
        }

        // Codec checks only fire when ffprobe actually reported something.
        if ($media->videoCodec !== null && ! in_array($media->videoCodec, ['h264', 'hevc'], true)) {
            $verdicts[] = ValidationVerdict::error(
                'unsupported_video_codec',
                sprintf('Instagram needs H.264 video. This file is %s.', strtoupper($media->videoCodec)),
                'reencode'
            );
        }

        if ($media->videoCodec !== null && $media->audioCodec === null) {
            $verdicts[] = ValidationVerdict::warning(
                'no_audio_track',
                'This video has no audio track. Instagram usually accepts silent Reels, but some accounts see them rejected.'
            );
        }

        if ($media->audioCodec !== null && $media->audioCodec !== 'aac') {
            $verdicts[] = ValidationVerdict::error(
                'unsupported_audio_codec',
                sprintf('Instagram needs AAC audio. This file uses %s.', strtoupper($media->audioCodec)),
                'reencode'
            );
        }

        return $verdicts;
    }

    /**
     * @return list<ValidationVerdict>
     */
    private function validateStory(ProbeResult $media): array
    {
        return $this->checkVertical($media, 'Stories');
    }

    /**
     * @return list<ValidationVerdict>
     */
    private function checkVertical(ProbeResult $media, string $surface): array
    {
        $ratio = $media->aspectRatio();

        if ($ratio === null) {
            return [];
        }

        $drift = abs($ratio - self::VERTICAL_ASPECT);

        if ($drift <= self::VERTICAL_TOLERANCE) {
            return [];
        }

        // Slightly off is a warning with a fix; wildly off is an error. Both
        // name the actual ratio, because "invalid aspect ratio" tells nobody
        // which of their four attachments is the problem.
        $level = $drift <= 0.12
            ? ValidationVerdict::WARNING
            : ValidationVerdict::ERROR;

        return [new ValidationVerdict(
            $level,
            'not_vertical',
            sprintf(
                'This is %s. Instagram %s need 9:16 — crop it, or pad it to fit.',
                $media->aspectLabel(),
                $surface
            ),
            'crop_or_pad'
        )];
    }

    /**
     * Caption limits. Instagram enforces all three; Facebook effectively none.
     *
     * @return list<ValidationVerdict>
     */
    public function validateCaption(?string $caption, Platform $platform): array
    {
        $caption ??= '';
        $verdicts = [];

        $length = mb_strlen($caption);
        $limit = $platform->captionLimit();

        if ($length > $limit) {
            $verdicts[] = ValidationVerdict::error(
                'caption_too_long',
                sprintf(
                    'This caption is %s characters. %s allows %s.',
                    number_format($length),
                    $platform->label(),
                    number_format($limit)
                )
            );
        }

        $hashtagLimit = $platform->hashtagLimit();
        $hashtags = $this->countHashtags($caption);

        if ($hashtagLimit !== null && $hashtags > $hashtagLimit) {
            $verdicts[] = ValidationVerdict::error(
                'too_many_hashtags',
                sprintf(
                    'This caption has %d hashtags. Instagram rejects anything over %d.',
                    $hashtags,
                    $hashtagLimit
                )
            );
        }

        $mentionLimit = $platform->mentionLimit();
        $mentions = $this->countMentions($caption);

        if ($mentionLimit !== null && $mentions > $mentionLimit) {
            $verdicts[] = ValidationVerdict::error(
                'too_many_mentions',
                sprintf(
                    'This caption mentions %d accounts. Instagram allows %d.',
                    $mentions,
                    $mentionLimit
                )
            );
        }

        return $verdicts;
    }

    /**
     * How many attachments this post may carry.
     *
     * Platform-aware on purpose: Facebook publishes a text-only feed post
     * perfectly happily, and Instagram cannot publish anything without media at
     * all. Applying Instagram's rule to both would block a legitimate Facebook
     * announcement.
     *
     * @return list<ValidationVerdict>
     */
    public function validateAttachmentCount(int $count, PostType $type, Platform $platform = Platform::Instagram): array
    {
        if ($count === 0) {
            if ($platform === Platform::Facebook && $type === PostType::Post) {
                return [];
            }

            return [ValidationVerdict::error(
                'too_few_media',
                $platform === Platform::Instagram
                    ? 'Instagram cannot publish without a photo or a video. Attach one, or drop Instagram from the destinations.'
                    : 'This post needs a photo or a video before it can be scheduled.'
            )];
        }

        if ($count < $type->minMedia()) {
            return [ValidationVerdict::error(
                'too_few_media',
                $type === PostType::Carousel
                    ? sprintf('A carousel needs at least 2 items. This one has %d.', $count)
                    : 'This post needs a photo or a video before it can be scheduled.'
            )];
        }

        if ($count > $type->maxMedia()) {
            return [ValidationVerdict::error(
                'too_many_media',
                $type === PostType::Carousel
                    ? sprintf('A carousel takes at most 10 items. This one has %d.', $count)
                    : sprintf('A %s takes one file. This one has %d.', strtolower($type->label()), $count)
            )];
        }

        return [];
    }

    public function countHashtags(string $caption): int
    {
        preg_match_all('/(?<!\w)#[\p{L}\p{N}_]+/u', $caption, $matches);

        return count($matches[0]);
    }

    public function countMentions(string $caption): int
    {
        preg_match_all('/(?<!\w)@[\p{L}\p{N}_.]+/u', $caption, $matches);

        return count($matches[0]);
    }

    private function friendlyFormat(string $mime): string
    {
        return match ($mime) {
            'image/heic', 'image/heif' => 'a HEIC image (the iPhone default)',
            'image/webp' => 'a WebP image',
            'image/gif' => 'a GIF',
            'image/png' => 'a PNG',
            'video/x-matroska' => 'an MKV video',
            'video/webm' => 'a WebM video',
            'video/x-msvideo' => 'an AVI video',
            default => 'a '.$mime.' file',
        };
    }
}
