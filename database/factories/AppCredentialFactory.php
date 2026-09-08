<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\AppCredential>
 */
class AppCredentialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'meta_app_id' => (string) $this->faker->numerify('###############'),
            'meta_app_secret' => 'test-app-secret-'.$this->faker->lexify('??????'),
            'graph_version' => 'v21.0',
            'redirect_uri' => 'https://example.test/oauth/facebook/callback',
            'is_verified' => true,
            'verified_at' => now(),
        ];
    }
}
