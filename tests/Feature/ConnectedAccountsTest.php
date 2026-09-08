<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TargetStatus;
use App\Livewire\Settings\ConnectedAccounts;
use App\Models\AppCredential;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ConnectedAccountsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->user = User::factory()->create(['workspace_id' => $this->workspace->id]);

        AppCredential::factory()->create(['workspace_id' => $this->workspace->id]);
    }

    public function test_a_non_admin_can_reach_connected_accounts(): void
    {
        $this->actingAs($this->user)
            ->get('/settings/accounts')
            ->assertOk()
            ->assertSee('Connected accounts');
    }

    public function test_it_shows_token_health_but_never_a_token(): void
    {
        SocialAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Spark Tires',
            'access_token' => 'EAAG-super-secret-token',
            'token_expires_at' => now()->addDays(40),
        ]);

        $this->actingAs($this->user)
            ->get('/settings/accounts')
            ->assertOk()
            ->assertSee('Spark Tires')
            ->assertSee('Healthy')
            ->assertDontSee('EAAG-super-secret-token');
    }

    public function test_an_expiring_token_is_called_out_with_days_remaining(): void
    {
        SocialAccount::factory()->expiringIn(5)->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Spark Tires',
        ]);

        $this->actingAs($this->user)
            ->get('/settings/accounts')
            ->assertOk()
            ->assertSee('Expires in 5 days')
            ->assertSee('Reconnect before it lapses');
    }

    public function test_an_expired_token_says_publishing_has_stopped(): void
    {
        SocialAccount::factory()->expired()->create([
            'workspace_id' => $this->workspace->id,
        ]);

        $this->actingAs($this->user)
            ->get('/settings/accounts')
            ->assertOk()
            ->assertSee('Token expired')
            ->assertSee('Reconnect to resume publishing');
    }

    /**
     * A Page and its linked Instagram account become two destinations, because
     * they fail independently and are selected independently.
     */
    public function test_connecting_a_page_also_connects_its_linked_instagram(): void
    {
        session()->put('meta.oauth.pages', [[
            'page_id' => '1122334455',
            'name' => 'Spark Tires',
            'username' => 'sparktires',
            'access_token' => 'page-token-abc',
            'avatar_url' => null,
            'instagram' => [
                'ig_user_id' => '17841400000000000',
                'username' => 'sparktires',
                'name' => 'Spark Tires',
                'avatar_url' => null,
            ],
        ]]);
        session()->put('meta.oauth.expires_at', now()->addDays(60)->toIso8601String());

        Livewire::actingAs($this->user)
            ->test(ConnectedAccounts::class)
            ->assertSet('selected', ['1122334455'])
            ->call('connectSelected');

        $this->assertSame(2, SocialAccount::withoutGlobalScopes()->count());

        $facebook = SocialAccount::withoutGlobalScopes()->where('platform', 'facebook')->firstOrFail();
        $instagram = SocialAccount::withoutGlobalScopes()->where('platform', 'instagram')->firstOrFail();

        $this->assertSame('1122334455', $facebook->page_id);
        $this->assertSame('17841400000000000', $instagram->ig_user_id);

        // Instagram publishes with the Page token, not one of its own.
        $this->assertSame('page-token-abc', $instagram->access_token);
    }

    /**
     * Page access tokens must not linger in the session past the selection step.
     */
    public function test_connecting_clears_the_discovered_pages_from_the_session(): void
    {
        session()->put('meta.oauth.pages', [[
            'page_id' => '1122334455',
            'name' => 'Spark Tires',
            'access_token' => 'page-token-abc',
            'instagram' => null,
        ]]);

        Livewire::actingAs($this->user)
            ->test(ConnectedAccounts::class)
            ->call('connectSelected');

        $this->assertNull(session('meta.oauth.pages'));
        $this->assertNull(session('meta.oauth.expires_at'));
    }

    public function test_an_unselected_page_is_not_connected(): void
    {
        session()->put('meta.oauth.pages', [
            ['page_id' => '111', 'name' => 'Wanted', 'access_token' => 't1', 'instagram' => null],
            ['page_id' => '222', 'name' => 'Not wanted', 'access_token' => 't2', 'instagram' => null],
        ]);

        Livewire::actingAs($this->user)
            ->test(ConnectedAccounts::class)
            ->set('selected', ['111'])
            ->call('connectSelected');

        $this->assertSame(1, SocialAccount::withoutGlobalScopes()->count());
        $this->assertSame('Wanted', SocialAccount::withoutGlobalScopes()->firstOrFail()->name);
    }

    /**
     * Disconnecting is not a silent delete. A post that stops going out must
     * say why.
     */
    public function test_disconnecting_marks_queued_posts_skipped_with_a_reason(): void
    {
        $account = SocialAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Spark Tires',
        ]);

        $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);

        $target = PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'status' => TargetStatus::Queued,
        ]);

        Livewire::actingAs($this->user)
            ->test(ConnectedAccounts::class)
            ->call('disconnect', $account->id);

        $target->refresh();

        $this->assertSame(TargetStatus::Skipped, $target->status);
        $this->assertSame('account_disconnected', $target->error_code);
        $this->assertStringContainsString('Spark Tires', (string) $target->error_message);

        // The row survives: post_targets cascade from it, so deleting would
        // take published permalinks, insights and logs with it.
        $account->refresh();

        $this->assertFalse($account->is_active);
        $this->assertNull($account->access_token);
        $this->assertFalse($account->isPublishable());
    }

    public function test_disconnecting_keeps_the_publish_history_intact(): void
    {
        $account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
        $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);

        $published = PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'status' => TargetStatus::Published,
            'external_id' => '1122334455_999',
            'permalink' => 'https://facebook.com/999',
        ]);

        Livewire::actingAs($this->user)
            ->test(ConnectedAccounts::class)
            ->call('disconnect', $account->id);

        $published->refresh();

        $this->assertSame(TargetStatus::Published, $published->status);
        $this->assertSame('https://facebook.com/999', $published->permalink);
        $this->assertSame(1, SocialAccount::withoutGlobalScopes()->count());
    }

    public function test_already_published_targets_are_left_alone_when_disconnecting(): void
    {
        $account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
        $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);

        $target = PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'status' => TargetStatus::Published,
        ]);

        Livewire::actingAs($this->user)
            ->test(ConnectedAccounts::class)
            ->call('disconnect', $account->id);

        // History is history. It is not rewritten by a disconnection.
        $this->assertSame(TargetStatus::Published, $target->refresh()->status);
    }

    public function test_connecting_is_offered_only_once_credentials_exist(): void
    {
        AppCredential::withoutGlobalScopes()->delete();

        $this->actingAs($this->user)
            ->get('/settings/accounts')
            ->assertOk()
            ->assertSee('Add Meta credentials')
            ->assertDontSee('href="'.route('oauth.facebook.redirect').'"', false);
    }

    public function test_the_connect_flow_refuses_to_start_without_credentials(): void
    {
        AppCredential::withoutGlobalScopes()->delete();
        Http::fake();

        $this->actingAs($this->user)
            ->get('/oauth/facebook/redirect')
            ->assertRedirect(route('settings.meta-app'));

        Http::assertNothingSent();
    }

    public function test_the_callback_refuses_a_mismatched_state(): void
    {
        $this->actingAs($this->user)
            ->withSession(['meta.oauth.state' => 'the-real-state'])
            ->get('/oauth/facebook/callback?code=abc&state=a-forged-state')
            ->assertRedirect(route('settings.accounts'))
            ->assertSessionHas('error');

        $this->assertSame(0, SocialAccount::withoutGlobalScopes()->count());
    }
}
