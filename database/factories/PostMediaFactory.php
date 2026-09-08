<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MediaSourceType;
use App\Enums\MediaStatus;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\PostMedia>
 */
class PostMediaFactory extends Factory
{
    protected $model = \App\Models\PostMedia::class;

    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'position' => 0,
            'source_type' => MediaSourceType::Url,
            'source_url' => 'https://cdn.example.test/image.jpg',
            'disk' => 'public',
            'stored_path' => 'media/2026/09/example.jpg',
            'public_url' => 'https://gnext.test/storage/media/2026/09/example.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 480_000,
            'width' => 1080,
            'height' => 1080,
            'status' => MediaStatus::Ready,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => MediaStatus::Pending,
            'stored_path' => null,
            'public_url' => null,
            'mime' => null,
            'width' => null,
            'height' => null,
        ]);
    }

    public function fromDrive(string $fileId = 'ABCdef123456789'): static
    {
        return $this->state(fn () => [
            'source_type' => MediaSourceType::Drive,
            'source_url' => 'https://drive.google.com/file/d/'.$fileId.'/view?usp=drive_link',
        ]);
    }

    public function reel(): static
    {
        return $this->state(fn () => [
            'mime' => 'video/mp4',
            'width' => 1080,
            'height' => 1920,
            'duration_seconds' => 24.5,
            'stored_path' => 'media/2026/09/example.mp4',
            'public_url' => 'https://gnext.test/storage/media/2026/09/example.mp4',
        ]);
    }
}
