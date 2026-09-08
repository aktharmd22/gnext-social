<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PostSource;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Post>
 */
class PostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'title' => $this->faker->sentence(4),
            'caption' => $this->faker->paragraph(),
            'type' => PostType::Post,
            'append_brand_footer' => true,
            'scheduled_at' => now()->addDays(3),
            'status' => PostStatus::Draft,
            'created_by' => User::factory(),
            'source' => PostSource::Manual,
        ];
    }

    public function status(PostStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::Published,
            'published_at' => now()->subDay(),
            'scheduled_at' => now()->subDay(),
        ]);
    }

    public function scheduledAt(\DateTimeInterface $when): static
    {
        return $this->state(fn () => ['scheduled_at' => $when]);
    }

    /**
     * Belonging to a given workspace, with an author inside it.
     */
    public function inWorkspace(Workspace $workspace): static
    {
        return $this->state(fn () => [
            'workspace_id' => $workspace->id,
            'created_by' => User::factory()->state(['workspace_id' => $workspace->id]),
        ]);
    }
}
