<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShellTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function everydayRoutes(): array
    {
        return [
            'dashboard' => ['/'],
            'calendar' => ['/calendar'],
            'posts' => ['/posts'],
            'approvals' => ['/approvals'],
            'media' => ['/media'],
            'insights' => ['/insights'],
            'import' => ['/import'],
            'templates' => ['/templates'],
            'profile' => ['/profile'],
        ];
    }

    #[DataProvider('everydayRoutes')]
    public function test_every_everyday_screen_renders_for_a_user(string $path): void
    {
        $this->actingAs(User::factory()->create())
            ->get($path)
            ->assertOk();
    }

    public function test_the_dashboard_is_the_home_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('Publishing activity');
    }

    /**
     * Dashboard first, then the calendar. The order is the order of the day:
     * what needs a decision, then where it sits in the month.
     */
    public function test_the_dashboard_is_the_first_item_in_the_nav(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->get('/calendar')
            ->assertOk()
            ->getContent();

        $this->assertLessThan(
            strpos($html, '>Calendar<'),
            strpos($html, '>Dashboard<'),
            'Dashboard must appear before Calendar in the sidebar.'
        );
    }

    public function test_the_calendar_states_the_timezone_it_is_showing(): void
    {
        // Every displayed time carries its zone. A bare "9:00" is a bug.
        $this->actingAs(User::factory()->create(['timezone' => 'Asia/Dubai']))
            ->get('/calendar')
            ->assertOk()
            ->assertSee('Asia/Dubai');
    }

    /**
     * Hiding a link is not access control, but showing one the user cannot use
     * is still a bug.
     *
     * Settings is now reachable by both roles, because API configuration lives
     * there. The activity log is not.
     */
    public function test_admin_only_navigation_is_hidden_from_a_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/calendar')
            ->assertOk()
            ->assertDontSee('href="'.route('activity').'"', false);
    }

    public function test_settings_navigation_is_offered_to_a_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/calendar')
            ->assertOk()
            ->assertSee('href="'.route('settings.meta-app').'"', false);
    }

    public function test_admin_navigation_is_shown_to_an_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/calendar')
            ->assertOk()
            ->assertSee('href="'.route('settings.meta-app').'"', false);
    }

    /**
     * An expiring token stops the product working, so it is a standing banner
     * rather than a dismissible toast.
     */
    public function test_an_admin_is_warned_about_an_expiring_token(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = User::factory()->admin()->create(['workspace_id' => $workspace->id]);

        SocialAccount::create([
            'workspace_id' => $workspace->id,
            'platform' => Platform::Facebook,
            'page_id' => '55443322',
            'name' => 'Expiring Page',
            'access_token' => 'token',
            'token_expires_at' => now()->addDays(5),
        ]);

        $this->actingAs($admin)
            ->get('/calendar')
            ->assertOk()
            ->assertSee('1 token expiring');
    }

    public function test_a_user_is_not_shown_token_warnings(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);

        SocialAccount::create([
            'workspace_id' => $workspace->id,
            'platform' => Platform::Facebook,
            'page_id' => '55443322',
            'name' => 'Expiring Page',
            'access_token' => 'token',
            'token_expires_at' => now()->addDays(5),
        ]);

        $this->actingAs($user)
            ->get('/calendar')
            ->assertOk()
            ->assertDontSee('token expiring');
    }
}
