<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'platform' => Platform::Facebook,
            'page_id' => (string) $this->faker->numerify('###############'),
            'ig_user_id' => null,
            'name' => $this->faker->company(),
            'username' => $this->faker->userName(),
            'access_token' => 'EAA-test-token-'.$this->faker->lexify('??????????'),
            'token_type' => 'page',
            'token_expires_at' => now()->addDays(59),
            'scopes' => ['pages_show_list', 'pages_manage_posts'],
            'is_active' => true,
        ];
    }

    public function instagram(): static
    {
        return $this->state(fn () => [
            'platform' => Platform::Instagram,
            'ig_user_id' => (string) $this->faker->numerify('###############'),
            'scopes' => ['instagram_basic', 'instagram_content_publish'],
        ]);
    }

    public function expiringIn(int $days): static
    {
        return $this->state(fn () => ['token_expires_at' => now()->addDays($days)]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['token_expires_at' => now()->subDay()]);
    }
}
