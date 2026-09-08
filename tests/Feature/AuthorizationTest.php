<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The capability matrix, verified by test rather than by hiding links.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function adminOnlyRoutes(): array
    {
        return [
            'brand' => ['/settings/brand'],
            'notifications' => ['/settings/notifications'],
            'import defaults' => ['/settings/import-defaults'],
            'users' => ['/settings/users'],
            'calendar events' => ['/settings/events'],
            'activity log' => ['/activity'],
        ];
    }

    /**
     * API configuration is open to both roles by explicit product decision,
     * overriding the original brief.
     *
     * @return array<string, array{0: string}>
     */
    public static function apiConfigurationRoutes(): array
    {
        return [
            'meta app' => ['/settings/meta-app'],
            'connected accounts' => ['/settings/accounts'],
        ];
    }

    #[DataProvider('apiConfigurationRoutes')]
    public function test_a_user_may_configure_the_api(string $path): void
    {
        $this->actingAs(User::factory()->create())
            ->get($path)
            ->assertOk();
    }

    #[DataProvider('apiConfigurationRoutes')]
    public function test_an_admin_may_configure_the_api(string $path): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get($path)
            ->assertOk();
    }

    /**
     * Widening who may configure the API must not widen who may read a stored
     * credential. Nobody can, at any role -- that is enforced by encryption and
     * hidden serialisation, not by a route guard.
     */
    public function test_configuring_the_api_does_not_confer_reading_secrets(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();

        foreach ([$user, $admin] as $actor) {
            $this->assertTrue($actor->can('manage-credentials'));
            $this->assertTrue($actor->can('manage-accounts'));
        }

        // Still admin-only: these are not API configuration.
        $this->assertFalse($user->can('manage-users'));
        $this->assertFalse($user->can('view-activity-log'));
        $this->assertFalse($user->can('view-publish-logs'));
        $this->assertFalse($user->can('approve-posts'));
        $this->assertFalse($user->can('publish-now'));
    }

    public function test_a_user_is_not_offered_admin_only_settings_tabs(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings/meta-app')
            ->assertOk()
            ->assertSee('Meta app')
            ->assertSee('Connected accounts')
            // A tab that 403s on click is worse than an absent one.
            ->assertDontSee('href="'.route('settings.users').'"', false)
            ->assertDontSee('href="'.route('settings.events').'"', false);
    }

    #[DataProvider('adminOnlyRoutes')]
    public function test_a_non_admin_is_refused_admin_routes(string $path): void
    {
        $this->actingAs(User::factory()->create())
            ->get($path)
            ->assertForbidden();
    }

    #[DataProvider('adminOnlyRoutes')]
    public function test_an_admin_may_reach_admin_routes(string $path): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get($path)
            ->assertOk();
    }

    public function test_a_user_may_edit_their_own_post(): void
    {
        $author = User::factory()->create();

        $post = Post::factory()->create([
            'workspace_id' => $author->workspace_id,
            'created_by' => $author->id,
        ]);

        $this->assertTrue($author->can('update', $post));
        $this->assertTrue($author->can('delete', $post));
    }

    public function test_a_user_may_not_edit_someone_elses_post(): void
    {
        $workspace = Workspace::factory()->create();

        $author = User::factory()->create(['workspace_id' => $workspace->id]);
        $colleague = User::factory()->create(['workspace_id' => $workspace->id]);

        $post = Post::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $author->id,
        ]);

        $this->assertFalse($colleague->can('update', $post));
        $this->assertFalse($colleague->can('delete', $post));
    }

    public function test_an_admin_may_edit_anyones_post(): void
    {
        $workspace = Workspace::factory()->create();

        $author = User::factory()->create(['workspace_id' => $workspace->id]);
        $admin = User::factory()->admin()->create(['workspace_id' => $workspace->id]);

        $post = Post::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $author->id,
        ]);

        $this->assertTrue($admin->can('update', $post));
    }

    /**
     * A job has already read the caption it is publishing. Editing now would
     * put content live that nobody reviewed.
     */
    public function test_nobody_may_edit_a_post_that_is_mid_publish(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = User::factory()->admin()->create(['workspace_id' => $workspace->id]);

        $post = Post::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $admin->id,
            'status' => PostStatus::Publishing,
        ]);

        $this->assertFalse($admin->can('update', $post));
    }

    public function test_only_an_admin_may_approve(): void
    {
        $workspace = Workspace::factory()->create();

        $author = User::factory()->create(['workspace_id' => $workspace->id]);
        $admin = User::factory()->admin()->create(['workspace_id' => $workspace->id]);

        $post = Post::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $author->id,
            'status' => PostStatus::PendingApproval,
        ]);

        $this->assertFalse($author->can('approve', $post));
        $this->assertTrue($admin->can('approve', $post));
    }

    public function test_only_an_admin_may_force_publish(): void
    {
        $workspace = Workspace::factory()->create();

        $author = User::factory()->create(['workspace_id' => $workspace->id]);
        $admin = User::factory()->admin()->create(['workspace_id' => $workspace->id]);

        $post = Post::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $author->id,
        ]);

        $this->assertFalse($author->can('publishNow', $post));
        $this->assertTrue($admin->can('publishNow', $post));
    }

    /**
     * The gates that stayed admin-only after API configuration was opened up.
     *
     * view-tokens, manage-credentials and manage-accounts are deliberately
     * absent from this list: see test_a_user_may_configure_the_api.
     */
    public function test_administrative_gates_remain_admin_only(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $abilities = [
            'manage-users',
            'view-publish-logs',
            'view-activity-log',
            'approve-posts',
            'publish-now',
            'manage-brand',
            'manage-notifications',
            'manage-import-defaults',
            'manage-calendar-events',
        ];

        foreach ($abilities as $ability) {
            $this->assertFalse($user->can($ability), "Expected a user to be denied [$ability].");
            $this->assertTrue($admin->can($ability), "Expected an admin to be allowed [$ability].");
        }
    }
}
