<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\AppCredential;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Secrets are encrypted at rest, never serialised, and never rendered.
 *
 * These are cheap tests guarding an expensive mistake: an app secret or a Page
 * access token reaching a browser, a log line, or a JSON payload.
 */
class SecretsAreNeverExposedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_app_secret_is_encrypted_at_rest(): void
    {
        $workspace = Workspace::factory()->create();

        AppCredential::create([
            'workspace_id' => $workspace->id,
            'meta_app_id' => '1234567890',
            'meta_app_secret' => 'super-secret-value',
            'graph_version' => 'v21.0',
            'redirect_uri' => 'https://example.test/oauth/facebook/callback',
        ]);

        $stored = DB::table('app_credentials')->value('meta_app_secret');

        $this->assertNotSame('super-secret-value', $stored);
        $this->assertStringNotContainsString('super-secret-value', (string) $stored);
    }

    public function test_the_app_secret_never_survives_serialisation(): void
    {
        $workspace = Workspace::factory()->create();

        $credential = AppCredential::create([
            'workspace_id' => $workspace->id,
            'meta_app_id' => '1234567890',
            'meta_app_secret' => 'super-secret-value',
            'graph_version' => 'v21.0',
            'redirect_uri' => 'https://example.test/oauth/facebook/callback',
        ]);

        $this->assertArrayNotHasKey('meta_app_secret', $credential->toArray());
        $this->assertArrayNotHasKey('webhook_verify_token', $credential->toArray());
        $this->assertStringNotContainsString('super-secret-value', $credential->toJson());
    }

    public function test_an_access_token_is_encrypted_at_rest(): void
    {
        $workspace = Workspace::factory()->create();

        SocialAccount::create([
            'workspace_id' => $workspace->id,
            'platform' => Platform::Facebook,
            'page_id' => '99887766',
            'name' => 'Test Page',
            'access_token' => 'EAAG-long-lived-page-token',
        ]);

        $stored = DB::table('social_accounts')->value('access_token');

        $this->assertNotSame('EAAG-long-lived-page-token', $stored);
        $this->assertStringNotContainsString('EAAG-long-lived-page-token', (string) $stored);
    }

    public function test_an_access_token_never_survives_serialisation(): void
    {
        $workspace = Workspace::factory()->create();

        $account = SocialAccount::create([
            'workspace_id' => $workspace->id,
            'platform' => Platform::Facebook,
            'page_id' => '99887766',
            'name' => 'Test Page',
            'access_token' => 'EAAG-long-lived-page-token',
        ]);

        $this->assertArrayNotHasKey('access_token', $account->toArray());
        $this->assertStringNotContainsString('EAAG-long-lived-page-token', $account->toJson());
    }

    public function test_a_users_two_factor_secret_never_survives_serialisation(): void
    {
        $user = User::factory()->create();

        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $user->toArray());
        $this->assertArrayNotHasKey('password', $user->toArray());
    }

    /**
     * Both roles may now configure the API. Neither may read back what is
     * stored -- the secret is write-only in the UI regardless of who is
     * looking, which is why widening access did not widen exposure.
     */
    public function test_no_secret_is_rendered_on_the_settings_screen_for_either_role(): void
    {
        $workspace = Workspace::factory()->create();

        AppCredential::create([
            'workspace_id' => $workspace->id,
            'meta_app_id' => '1234567890',
            'meta_app_secret' => 'super-secret-value',
            'graph_version' => 'v21.0',
            'redirect_uri' => 'https://example.test/oauth/facebook/callback',
        ]);

        SocialAccount::create([
            'workspace_id' => $workspace->id,
            'platform' => Platform::Facebook,
            'page_id' => '99887766',
            'name' => 'Test Page',
            'access_token' => 'EAAG-long-lived-page-token',
        ]);

        $actors = [
            'user' => User::factory()->create(['workspace_id' => $workspace->id]),
            'admin' => User::factory()->admin()->create(['workspace_id' => $workspace->id]),
        ];

        foreach ($actors as $role => $actor) {
            foreach (['/settings/meta-app', '/settings/accounts'] as $path) {
                $this->actingAs($actor)
                    ->get($path)
                    ->assertOk()
                    ->assertDontSee('super-secret-value')
                    ->assertDontSee('EAAG-long-lived-page-token');
            }
        }
    }

    public function test_no_secret_is_rendered_on_the_settings_screen(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = User::factory()->admin()->create(['workspace_id' => $workspace->id]);

        AppCredential::create([
            'workspace_id' => $workspace->id,
            'meta_app_id' => '1234567890',
            'meta_app_secret' => 'super-secret-value',
            'graph_version' => 'v21.0',
            'redirect_uri' => 'https://example.test/oauth/facebook/callback',
        ]);

        SocialAccount::create([
            'workspace_id' => $workspace->id,
            'platform' => Platform::Facebook,
            'page_id' => '99887766',
            'name' => 'Test Page',
            'access_token' => 'EAAG-long-lived-page-token',
        ]);

        $this->actingAs($admin)
            ->get('/settings/meta-app')
            ->assertOk()
            ->assertDontSee('super-secret-value')
            ->assertDontSee('EAAG-long-lived-page-token');

        $this->actingAs($admin)
            ->get('/settings/accounts')
            ->assertOk()
            ->assertDontSee('EAAG-long-lived-page-token');
    }
}
