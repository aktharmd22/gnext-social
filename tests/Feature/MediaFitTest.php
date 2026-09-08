<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MediaSourceType;
use App\Enums\MediaStatus;
use App\Enums\PostType;
use App\Livewire\Composer;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\AutoFitService;
use App\Services\Media\MediaIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Direct upload, and making media fit rather than only refusing it.
 */
class MediaFitTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->user = User::factory()->admin()->create(['workspace_id' => $this->workspace->id]);

        SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 30, 90, 200));

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes;
    }

    private function storedMedia(int $width, int $height): PostMedia
    {
        Storage::fake('public');

        $post = Post::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'type' => PostType::Reel,
        ]);

        $path = 'media/2026/09/source.jpg';
        Storage::disk('public')->put($path, $this->jpeg($width, $height));

        return PostMedia::factory()->create([
            'post_id' => $post->id,
            'disk' => 'public',
            'stored_path' => $path,
            'mime' => 'image/jpeg',
            'width' => $width,
            'height' => $height,
            'status' => MediaStatus::Ready,
        ]);
    }

    // ================================================================ upload

    public function test_a_file_can_be_uploaded_straight_into_the_composer(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->createWithContent('creative.jpg', $this->jpeg(1080, 1350));

        Livewire::actingAs($this->user)
            ->test(Composer::class)
            ->call('openNew')
            ->set('caption', 'With an uploaded image.')
            ->set('upload', $file)
            ->assertHasNoErrors();

        $media = PostMedia::query()->firstOrFail();

        $this->assertSame(MediaSourceType::Upload, $media->source_type);
        // Ingested inline, not queued: the file is already here.
        $this->assertSame(MediaStatus::Ready, $media->status);
        $this->assertSame(1080, $media->width);
        $this->assertSame(1350, $media->height);

        Storage::disk('public')->assertExists($media->stored_path);
    }

    public function test_an_unsupported_upload_is_refused_by_name(): void
    {
        Storage::fake('public');

        Livewire::actingAs($this->user)
            ->test(Composer::class)
            ->call('openNew')
            ->set('upload', UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'))
            ->assertHasErrors('upload');

        $this->assertSame(0, PostMedia::query()->count());
    }

    /**
     * An upload is probed exactly like a download: a .jpg that is really a PNG
     * fails at Meta the same way whichever route it arrived by.
     */
    public function test_an_upload_is_probed_rather_than_trusted(): void
    {
        Storage::fake('public');

        $png = (function () {
            $image = imagecreatetruecolor(600, 600);
            ob_start();
            imagepng($image);
            $bytes = (string) ob_get_clean();
            imagedestroy($image);

            return $bytes;
        })();

        $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);

        $media = PostMedia::factory()->pending()->create(['post_id' => $post->id]);

        $temp = tempnam(sys_get_temp_dir(), 'gnext');
        file_put_contents($temp, $png);

        app(MediaIngestor::class)->ingestUploadedFile($media, $temp, 'mislabelled.jpg');

        $media->refresh();

        $this->assertSame('image/png', $media->mime);
        $this->assertStringEndsWith('.png', (string) $media->stored_path);

        @unlink($temp);
    }

    // =============================================================== refit

    public function test_cropping_a_square_to_nine_by_sixteen(): void
    {
        $media = $this->storedMedia(1080, 1080);

        app(AutoFitService::class)->refit($media, PostType::Reel, AutoFitService::CROP);

        $media->refresh();

        // 9:16 is 0.5625. Cropping a square keeps the full height and narrows.
        $this->assertEqualsWithDelta(0.5625, $media->width / $media->height, 0.01);
        $this->assertSame(1080, $media->height, 'A centre crop should keep the full height.');
    }

    public function test_padding_a_square_to_nine_by_sixteen(): void
    {
        $media = $this->storedMedia(1080, 1080);

        app(AutoFitService::class)->refit($media, PostType::Reel, AutoFitService::PAD);

        $media->refresh();

        $this->assertEqualsWithDelta(0.5625, $media->width / $media->height, 0.01);
        // Padding grows the canvas rather than discarding pixels.
        $this->assertGreaterThan(1080, $media->height);
    }

    public function test_a_feed_post_is_refitted_to_four_by_five(): void
    {
        $media = $this->storedMedia(1600, 400);

        app(AutoFitService::class)->refit($media, PostType::Post, AutoFitService::CROP);

        $media->refresh();

        $this->assertEqualsWithDelta(0.8, $media->width / $media->height, 0.01);
    }

    /**
     * The original is never overwritten: a bad crop must be one click to undo,
     * and the same asset may be reused elsewhere at its own aspect.
     */
    public function test_refitting_writes_a_new_file_and_leaves_the_original(): void
    {
        $media = $this->storedMedia(1080, 1080);
        $original = $media->stored_path;

        app(AutoFitService::class)->refit($media, PostType::Reel, AutoFitService::CROP);

        $this->assertNotSame($original, $media->fresh()->stored_path);
        Storage::disk('public')->assertExists($original);
    }

    public function test_refitting_regenerates_the_thumbnail(): void
    {
        $media = $this->storedMedia(1080, 1080);

        app(AutoFitService::class)->refit($media, PostType::Reel, AutoFitService::CROP);

        $media->refresh();

        $this->assertNotNull($media->thumbnail_path);
        Storage::disk('public')->assertExists($media->thumbnail_path);
    }

    public function test_the_composer_exposes_the_fix(): void
    {
        $media = $this->storedMedia(1080, 1080);

        Livewire::actingAs($this->user)
            ->test(Composer::class)
            ->call('openExisting', $media->post_id)
            ->call('refit', $media->id, AutoFitService::CROP)
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(0.5625, $media->fresh()->width / $media->fresh()->height, 0.01);
    }

    public function test_refitting_something_never_fetched_says_so(): void
    {
        Storage::fake('public');

        $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);
        $media = PostMedia::factory()->pending()->create(['post_id' => $post->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has not been fetched yet');

        app(AutoFitService::class)->refit($media, PostType::Reel);
    }
}
