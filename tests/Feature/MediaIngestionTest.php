<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MediaStatus;
use App\Models\Post;
use App\Models\PostMedia;
use App\Services\Media\MediaIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The pipeline that stops Google Drive links reaching Meta.
 *
 * Meta fetches media from whatever URL we give it, server-side, with no browser
 * and no Google session. Handing it a Drive share link produces a cryptic
 * failure hours later. These tests pin the behaviour that prevents that.
 */
class MediaIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function jpeg(int $width = 1080, int $height = 1080): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 20, 90, 200));

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes;
    }

    private function mediaFrom(string $sourceUrl): PostMedia
    {
        $post = Post::factory()->create();

        return PostMedia::factory()->pending()->create([
            'post_id' => $post->id,
            'source_url' => $sourceUrl,
        ]);
    }

    public function test_a_drive_share_link_is_rewritten_and_downloaded(): void
    {
        Storage::fake('public');

        $jpeg = $this->jpeg();

        Http::fake([
            // The share link itself must never be requested.
            'drive.google.com/file/*' => Http::response('<html>viewer</html>', 200, ['Content-Type' => 'text/html']),
            'drive.google.com/uc*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $media = $this->mediaFrom('https://drive.google.com/file/d/1A2b3C4d5E6f7G8h/view?usp=drive_link');

        app(MediaIngestor::class)->ingest($media);

        $media->refresh();

        $this->assertSame(MediaStatus::Ready, $media->status);
        $this->assertSame('image/jpeg', $media->mime);
        $this->assertSame(1080, $media->width);
        $this->assertSame(1080, $media->height);
        $this->assertNotNull($media->public_url);

        // The bytes really landed on the disk Meta will fetch from.
        Storage::disk('public')->assertExists($media->stored_path);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'uc?export=download')
            && str_contains($request->url(), '1A2b3C4d5E6f7G8h'));
    }

    /**
     * Google answers 200 with an HTML warning page for large files instead of
     * the bytes. Storing that as an "image" is the silent failure this guards.
     */
    public function test_it_follows_the_drive_virus_scan_interstitial(): void
    {
        Storage::fake('public');

        $jpeg = $this->jpeg(1080, 1350);

        Http::fake([
            'drive.google.com/uc*' => Http::response(
                '<html><form><input type="hidden" name="confirm" value="t-abc123"></form></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'drive.usercontent.google.com/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $media = $this->mediaFrom('https://drive.google.com/file/d/1A2b3C4d5E6f7G8h/view');

        app(MediaIngestor::class)->ingest($media);

        $media->refresh();

        $this->assertSame(MediaStatus::Ready, $media->status);
        $this->assertSame(1350, $media->height);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'confirm=t-abc123'));
    }

    public function test_an_html_page_is_never_stored_as_media(): void
    {
        Storage::fake('public');

        Http::fake([
            '*' => Http::response(
                '<!DOCTYPE html><html><body>You need access</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $media = $this->mediaFrom('https://drive.google.com/file/d/1A2b3C4d5E6f7G8h/view');

        app(MediaIngestor::class)->ingest($media);

        $media->refresh();

        $this->assertSame(MediaStatus::Failed, $media->status);
        $this->assertNull($media->stored_path);

        // And it says what to actually do about it.
        $this->assertStringContainsString('Anyone with the link', (string) $media->error_message);
    }

    public function test_a_plain_url_is_fetched_as_given(): void
    {
        Storage::fake('public');

        Http::fake([
            'cdn.example.test/*' => Http::response($this->jpeg(1080, 566), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $media = $this->mediaFrom('https://cdn.example.test/creative/banner.jpg');

        app(MediaIngestor::class)->ingest($media);

        $media->refresh();

        $this->assertSame(MediaStatus::Ready, $media->status);
        $this->assertSame(1080, $media->width);
        $this->assertSame(566, $media->height);
    }

    public function test_a_drive_folder_is_refused_before_any_request_is_made(): void
    {
        Storage::fake('public');
        Http::fake();

        $media = $this->mediaFrom('https://drive.google.com/drive/folders/1A2b3C4d5E6f7G8h');

        app(MediaIngestor::class)->ingest($media);

        $media->refresh();

        $this->assertSame(MediaStatus::Failed, $media->status);
        $this->assertStringContainsString('folder', (string) $media->error_message);

        Http::assertNothingSent();
    }

    public function test_a_file_over_the_cap_is_refused_with_both_sizes_named(): void
    {
        Storage::fake('public');
        config(['gnext.media.max_bytes' => 1024]);

        Http::fake([
            '*' => Http::response(str_repeat('x', 4096), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $media = $this->mediaFrom('https://cdn.example.test/huge.jpg');

        app(MediaIngestor::class)->ingest($media);

        $media->refresh();

        $this->assertSame(MediaStatus::Failed, $media->status);
        $this->assertStringContainsString('4 KB', (string) $media->error_message);
        $this->assertStringContainsString('1 KB', (string) $media->error_message);
    }

    public function test_a_thumbnail_is_generated_for_the_calendar(): void
    {
        Storage::fake('public');

        Http::fake([
            '*' => Http::response($this->jpeg(1600, 1600), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $media = $this->mediaFrom('https://cdn.example.test/big.jpg');

        app(MediaIngestor::class)->ingest($media);

        $media->refresh();

        $this->assertNotNull($media->thumbnail_path);
        Storage::disk('public')->assertExists($media->thumbnail_path);

        // A month of full-size media would be 100MB+ down the wire.
        $this->assertLessThan(
            (int) $media->size_bytes,
            Storage::disk('public')->size($media->thumbnail_path)
        );
    }

    /**
     * The extension is a guess until the bytes are read. Meta is picky about
     * URLs whose extension contradicts their content.
     */
    public function test_the_stored_extension_matches_the_real_content(): void
    {
        Storage::fake('public');

        Http::fake([
            // Claims PNG in the URL, is actually a JPEG.
            '*' => Http::response($this->jpeg(), 200, ['Content-Type' => 'application/octet-stream']),
        ]);

        $media = $this->mediaFrom('https://cdn.example.test/mislabelled.png');

        app(MediaIngestor::class)->ingest($media);

        $media->refresh();

        $this->assertSame('image/jpeg', $media->mime);
        $this->assertStringEndsWith('.jpg', (string) $media->stored_path);
    }

    public function test_a_dead_link_fails_the_row_and_not_the_process(): void
    {
        Storage::fake('public');

        Http::fake(['*' => Http::response('', 404)]);

        $media = $this->mediaFrom('https://cdn.example.test/gone.jpg');

        app(MediaIngestor::class)->ingest($media);

        $media->refresh();

        $this->assertSame(MediaStatus::Failed, $media->status);
        $this->assertNotEmpty($media->error_message);
    }
}
