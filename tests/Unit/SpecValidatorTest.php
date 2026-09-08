<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Services\Media\ProbeResult;
use App\Services\Media\SpecValidator;
use App\Services\Media\ValidationVerdict;
use Tests\TestCase;

/**
 * Platform rules, checked in the composer rather than at publish time.
 *
 * The value of this class is entirely in its error copy: Meta's own messages
 * are cryptic, and a rejection at 09:00 with nobody awake is the failure mode
 * this exists to prevent.
 */
class SpecValidatorTest extends TestCase
{
    private SpecValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new SpecValidator;
    }

    private function image(int $width, int $height, string $mime = 'image/jpeg'): ProbeResult
    {
        return new ProbeResult(mime: $mime, width: $width, height: $height);
    }

    private function video(int $width, int $height, float $duration, string $mime = 'video/mp4'): ProbeResult
    {
        return new ProbeResult(
            mime: $mime,
            width: $width,
            height: $height,
            duration: $duration,
            videoCodec: 'h264',
            audioCodec: 'aac',
        );
    }

    /**
     * @param  list<ValidationVerdict>  $verdicts
     */
    private function codes(array $verdicts): array
    {
        return array_map(fn (ValidationVerdict $v) => $v->code, $verdicts);
    }

    // ------------------------------------------------------------ feed images

    public function test_a_square_image_is_accepted_for_the_instagram_feed(): void
    {
        $verdicts = $this->validator->validateMedia(
            $this->image(1080, 1080),
            Platform::Instagram,
            PostType::Post
        );

        $this->assertSame([], $verdicts);
    }

    public function test_a_four_by_five_portrait_is_accepted(): void
    {
        $this->assertSame([], $this->validator->validateMedia(
            $this->image(1080, 1350),
            Platform::Instagram,
            PostType::Post
        ));
    }

    public function test_an_over_tall_image_is_rejected_and_offered_a_fix(): void
    {
        $verdicts = $this->validator->validateMedia(
            $this->image(1080, 1920),
            Platform::Instagram,
            PostType::Post
        );

        $this->assertContains('aspect_out_of_range', $this->codes($verdicts));

        $verdict = $verdicts[0];

        $this->assertTrue($verdict->isError());
        $this->assertSame('crop_or_pad', $verdict->fix);

        // It names the actual ratio and the allowed range.
        $this->assertStringContainsString('9:16', $verdict->message);
        $this->assertStringContainsString('4:5', $verdict->message);
        $this->assertStringContainsString('1.91:1', $verdict->message);
    }

    public function test_a_narrow_image_is_rejected_with_both_numbers(): void
    {
        $verdicts = $this->validator->validateMedia(
            $this->image(240, 240),
            Platform::Instagram,
            PostType::Post
        );

        $this->assertContains('too_narrow', $this->codes($verdicts));
        $this->assertStringContainsString('240px', $verdicts[0]->message);
        $this->assertStringContainsString('320px', $verdicts[0]->message);
    }

    public function test_a_heic_export_is_rejected_by_name(): void
    {
        $verdicts = $this->validator->validateMedia(
            $this->image(1080, 1080, 'image/heic'),
            Platform::Instagram,
            PostType::Post
        );

        $this->assertContains('unsupported_image_format', $this->codes($verdicts));

        // Naming the iPhone default is the difference between a shrug and a fix.
        $this->assertStringContainsString('iPhone default', $verdicts[0]->message);
        $this->assertSame('convert_jpeg', $verdicts[0]->fix);
    }

    // ------------------------------------------------------------------ reels

    public function test_a_nine_by_sixteen_reel_is_accepted(): void
    {
        $this->assertSame([], $this->validator->validateMedia(
            $this->video(1080, 1920, 24.0),
            Platform::Instagram,
            PostType::Reel
        ));
    }

    /**
     * The exact case the brief calls out.
     */
    public function test_a_square_reel_is_rejected_with_the_message_from_the_brief(): void
    {
        $verdicts = $this->validator->validateMedia(
            $this->video(1080, 1080, 24.0),
            Platform::Instagram,
            PostType::Reel
        );

        $this->assertContains('not_vertical', $this->codes($verdicts));

        $verdict = collect($verdicts)->firstWhere('code', 'not_vertical');

        $this->assertStringContainsString('1:1', $verdict->message);
        $this->assertStringContainsString('9:16', $verdict->message);
        $this->assertSame('crop_or_pad', $verdict->fix);
    }

    public function test_a_reel_under_three_seconds_is_rejected(): void
    {
        $verdicts = $this->validator->validateMedia(
            $this->video(1080, 1920, 2.4),
            Platform::Instagram,
            PostType::Reel
        );

        $this->assertContains('too_short', $this->codes($verdicts));
        $this->assertStringContainsString('2.4 seconds', $verdicts[0]->message);
    }

    public function test_a_reel_over_fifteen_minutes_is_rejected(): void
    {
        $verdicts = $this->validator->validateMedia(
            $this->video(1080, 1920, 1200.0),
            Platform::Instagram,
            PostType::Reel
        );

        $this->assertContains('too_long', $this->codes($verdicts));
        $this->assertSame('trim', collect($verdicts)->firstWhere('code', 'too_long')->fix);
    }

    public function test_a_non_h264_reel_is_rejected_by_codec(): void
    {
        $media = new ProbeResult(
            mime: 'video/mp4',
            width: 1080,
            height: 1920,
            duration: 20.0,
            videoCodec: 'vp9',
            audioCodec: 'aac',
        );

        $verdicts = $this->validator->validateMedia($media, Platform::Instagram, PostType::Reel);

        $this->assertContains('unsupported_video_codec', $this->codes($verdicts));
        $this->assertStringContainsString('VP9', collect($verdicts)->firstWhere('code', 'unsupported_video_codec')->message);
    }

    public function test_an_image_attached_to_a_reel_is_rejected_immediately(): void
    {
        $verdicts = $this->validator->validateMedia(
            $this->image(1080, 1920),
            Platform::Instagram,
            PostType::Reel
        );

        $this->assertSame(['image_in_reel'], $this->codes($verdicts));
    }

    /**
     * Facebook accepts almost anything and crops for display. Flagging the same
     * files there would train people to ignore warnings.
     */
    public function test_facebook_is_not_held_to_instagram_rules(): void
    {
        $this->assertSame([], $this->validator->validateMedia(
            $this->image(1080, 1920),
            Platform::Facebook,
            PostType::Post
        ));
    }

    // --------------------------------------------------------------- captions

    public function test_an_over_long_instagram_caption_is_rejected_with_both_numbers(): void
    {
        $verdicts = $this->validator->validateCaption(str_repeat('a', 2500), Platform::Instagram);

        $this->assertContains('caption_too_long', $this->codes($verdicts));
        $this->assertStringContainsString('2,500', $verdicts[0]->message);
        $this->assertStringContainsString('2,200', $verdicts[0]->message);
    }

    public function test_the_same_caption_is_fine_on_facebook(): void
    {
        $this->assertSame([], $this->validator->validateCaption(str_repeat('a', 2500), Platform::Facebook));
    }

    public function test_more_than_thirty_hashtags_is_rejected(): void
    {
        $caption = 'Great day '.implode(' ', array_map(fn ($i) => '#tag'.$i, range(1, 31)));

        $verdicts = $this->validator->validateCaption($caption, Platform::Instagram);

        $this->assertContains('too_many_hashtags', $this->codes($verdicts));
        $this->assertStringContainsString('31 hashtags', $verdicts[0]->message);
    }

    public function test_hashtags_and_mentions_are_counted_accurately(): void
    {
        $caption = "New drop #dubai #uae\nThanks @sparktires and @someone.else — email a@b.com";

        $this->assertSame(2, $this->validator->countHashtags($caption));

        // The address is not a mention: the @ is preceded by a word character.
        $this->assertSame(2, $this->validator->countMentions($caption));
    }

    public function test_arabic_hashtags_are_counted(): void
    {
        $this->assertSame(2, $this->validator->countHashtags('عرض خاص #دبي #الإمارات'));
    }

    // -------------------------------------------------------------- carousels

    public function test_a_carousel_needs_at_least_two_items(): void
    {
        $verdicts = $this->validator->validateAttachmentCount(1, PostType::Carousel);

        $this->assertContains('too_few_media', $this->codes($verdicts));
        $this->assertStringContainsString('at least 2', $verdicts[0]->message);
    }

    public function test_a_carousel_caps_at_ten_items(): void
    {
        $verdicts = $this->validator->validateAttachmentCount(11, PostType::Carousel);

        $this->assertContains('too_many_media', $this->codes($verdicts));
        $this->assertStringContainsString('at most 10', $verdicts[0]->message);
    }

    public function test_a_carousel_between_two_and_ten_is_accepted(): void
    {
        $this->assertSame([], $this->validator->validateAttachmentCount(2, PostType::Carousel));
        $this->assertSame([], $this->validator->validateAttachmentCount(10, PostType::Carousel));
    }

    public function test_a_post_with_no_media_is_told_what_it_needs(): void
    {
        $verdicts = $this->validator->validateAttachmentCount(0, PostType::Post);

        $this->assertContains('too_few_media', $this->codes($verdicts));
        $this->assertStringContainsString('photo or a video', $verdicts[0]->message);
    }
}
