<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TargetStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\PostTarget>
 */
class PostTargetFactory extends Factory
{
    protected $model = \App\Models\PostTarget::class;

    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'social_account_id' => SocialAccount::factory(),
            'status' => TargetStatus::Queued,
            'attempts' => 0,
        ];
    }

    public function status(TargetStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
