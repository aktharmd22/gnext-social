<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Settings\MetaApp;
use App\Models\ActivityLog;
use App\Models\AppCredential;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings > Meta app.
 *
 * API configuration is open to both roles by product decision. The guarantee
 * that survives that change, and which these tests pin, is that the stored
 * secret is write-only: no role can read it back, through the page or through a
 * Livewire payload.
 */
class MetaAppSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function user(bool $admin = false): User
    {
        $workspace = Workspace::factory()->create();

        return $admin
            ? User::factory()->admin()->create(['workspace_id' => $workspace->id])
            : User::factory()->create(['workspace_id' => $workspace->id]);
    }

    public function test_a_non_admin_can_configure_the_api(): void
    {
        $this->actingAs($this->user())
            ->get('/settings/meta-app')
            ->assertOk()
            ->assertSee('Meta app')
            ->assertSee('OAuth redirect URI');
    }

    public function test_saving_stores_the_secret_encrypted(): void
    {
        $user = $this->user();

        Livewire::actingAs($user)
            ->test(MetaApp::class)
            ->set('metaAppId', '1234567890123456')
            ->set('graphVersion', 'v21.0')
            ->set('redirectUri', 'https://gnext.test/oauth/facebook/callback')
            ->set('newSecret', 'a-real-looking-app-secret-value')
            ->call('save')
            ->assertHasNoErrors();

        $credential = AppCredential::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('1234567890123456', $credential->meta_app_id);
        $this->assertSame('a-real-looking-app-secret-value', $credential->meta_app_secret);

        // What is actually on disk is not the secret.
        $raw = DB::table('app_credentials')->value('meta_app_secret');
        $this->assertStringNotContainsString('a-real-looking-app-secret-value', (string) $raw);
    }

    /**
     * The component must never load the stored secret into a public property,
     * because Livewire serialises those to the browser on every request.
     */
    public function test_the_stored_secret_never_reaches_the_browser(): void
    {
        $user = $this->user();

        AppCredential::factory()->create([
            'workspace_id' => $user->workspace_id,
            'meta_app_secret' => 'super-secret-value',
        ]);

        Livewire::actingAs($user)
            ->test(MetaApp::class)
            ->assertSet('newSecret', '')
            ->assertSet('hasStoredSecret', true)
            ->assertDontSee('super-secret-value')
            // The mask, and the way back to changing it.
            ->assertSee('Replace secret');

        $this->actingAs($user)
            ->get('/settings/meta-app')
            ->assertOk()
            ->assertDontSee('super-secret-value');
    }

    public function test_a_first_time_setup_requires_a_secret(): void
    {
        Livewire::actingAs($this->user())
            ->test(MetaApp::class)
            ->set('metaAppId', '1234567890123456')
            ->set('redirectUri', 'https://gnext.test/oauth/facebook/callback')
            ->set('newSecret', '')
            ->call('save')
            ->assertHasErrors('newSecret');
    }

    public function test_an_existing_secret_survives_an_unrelated_edit(): void
    {
        $user = $this->user();

        AppCredential::factory()->create([
            'workspace_id' => $user->workspace_id,
            'meta_app_id' => '1111111111111111',
            'meta_app_secret' => 'keep-me',
        ]);

        Livewire::actingAs($user)
            ->test(MetaApp::class)
            ->set('metaAppId', '2222222222222222')
            ->call('save')
            ->assertHasNoErrors();

        $credential = AppCredential::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('2222222222222222', $credential->meta_app_id);
        $this->assertSame('keep-me', $credential->meta_app_secret);
    }

    public function test_replacing_the_secret_invalidates_the_previous_verification(): void
    {
        $user = $this->user();

        AppCredential::factory()->create([
            'workspace_id' => $user->workspace_id,
            'is_verified' => true,
            'verified_at' => now()->subDay(),
        ]);

        Livewire::actingAs($user)
            ->test(MetaApp::class)
            ->call('startReplacingSecret')
            ->set('newSecret', 'a-brand-new-rotated-secret')
            ->call('save')
            ->assertHasNoErrors();

        $credential = AppCredential::withoutGlobalScopes()->firstOrFail();

        $this->assertFalse($credential->is_verified);
        $this->assertNull($credential->verified_at);
    }

    public function test_a_bad_app_id_is_refused_in_plain_language(): void
    {
        Livewire::actingAs($this->user())
            ->test(MetaApp::class)
            ->set('metaAppId', 'not-an-id')
            ->set('newSecret', 'a-real-looking-app-secret-value')
            ->call('save')
            ->assertHasErrors('metaAppId');
    }

    public function test_testing_the_connection_reports_success_and_scopes(): void
    {
        $user = $this->user();

        AppCredential::factory()->create(['workspace_id' => $user->workspace_id]);

        Http::fake([
            '*debug_token*' => Http::response([
                'data' => [
                    'is_valid' => true,
                    'app_id' => '1234567890123456',
                    'type' => 'APP',
                    'scopes' => ['pages_show_list', 'pages_manage_posts'],
                ],
            ]),
        ]);

        $component = Livewire::actingAs($user)
            ->test(MetaApp::class)
            ->call('testConnection');

        $result = $component->get('testResult');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Connected to Meta', $result['headline']);
        $this->assertStringContainsString('pages_manage_posts', $result['detail']);

        // It also names what is still missing, rather than implying all is well.
        $this->assertContains('instagram_content_publish', $result['missing']);

        $this->assertTrue(AppCredential::withoutGlobalScopes()->firstOrFail()->is_verified);
    }

    public function test_testing_the_connection_explains_a_rejection(): void
    {
        $user = $this->user();

        AppCredential::factory()->create([
            'workspace_id' => $user->workspace_id,
            'is_verified' => true,
        ]);

        Http::fake([
            '*debug_token*' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token.', 'code' => 190],
            ], 400),
        ]);

        $result = Livewire::actingAs($user)
            ->test(MetaApp::class)
            ->call('testConnection')
            ->get('testResult');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('rejected these credentials', $result['headline']);

        $this->assertFalse(AppCredential::withoutGlobalScopes()->firstOrFail()->is_verified);
    }

    public function test_testing_before_saving_says_so_rather_than_calling_meta(): void
    {
        Http::fake();

        $result = Livewire::actingAs($this->user())
            ->test(MetaApp::class)
            ->call('testConnection')
            ->get('testResult');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Save your App ID and secret first', $result['headline']);

        Http::assertNothingSent();
    }

    /**
     * Who replaced the app secret is exactly what an audit trail is for. What
     * they replaced it with is exactly what it is not for.
     */
    public function test_the_change_is_logged_without_recording_the_secret(): void
    {
        $user = $this->user();

        Livewire::actingAs($user)
            ->test(MetaApp::class)
            ->set('metaAppId', '1234567890123456')
            ->set('redirectUri', 'https://gnext.test/oauth/facebook/callback')
            ->set('newSecret', 'a-real-looking-app-secret-value')
            ->call('save')
            ->assertHasNoErrors();

        $log = ActivityLog::withoutGlobalScopes()->where('action', 'meta_app.updated')->firstOrFail();

        $this->assertSame($user->id, $log->user_id);

        $encoded = json_encode($log->changes);

        $this->assertStringNotContainsString('a-real-looking-app-secret-value', (string) $encoded);
        $this->assertSame(['changed' => true], $log->changes['meta_app_secret']);
    }
}
