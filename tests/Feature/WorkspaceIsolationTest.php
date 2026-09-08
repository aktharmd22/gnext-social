<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The workspace column exists before it is needed. These tests are what make it
 * more than a column.
 */
class WorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_queries_are_scoped_to_the_established_workspace(): void
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();

        Post::factory()->count(3)->create(['workspace_id' => $mine->id]);
        Post::factory()->count(2)->create(['workspace_id' => $theirs->id]);

        app(WorkspaceContext::class)->set($mine->id);

        $this->assertSame(3, Post::query()->count());

        app(WorkspaceContext::class)->set($theirs->id);

        $this->assertSame(2, Post::query()->count());
    }

    public function test_a_post_from_another_workspace_cannot_be_found(): void
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();

        $foreign = Post::factory()->create(['workspace_id' => $theirs->id]);

        app(WorkspaceContext::class)->set($mine->id);

        $this->assertNull(Post::query()->find($foreign->id));
    }

    public function test_new_records_are_stamped_with_the_current_workspace(): void
    {
        $workspace = Workspace::factory()->create();
        $author = User::factory()->create(['workspace_id' => $workspace->id]);

        app(WorkspaceContext::class)->set($workspace->id);

        // Deliberately omitting workspace_id: the trait must supply it, because
        // one forgotten call site would otherwise be a cross-tenant leak.
        $post = Post::create([
            'title' => 'Stamped automatically',
            'created_by' => $author->id,
        ]);

        $this->assertSame($workspace->id, $post->workspace_id);
    }

    public function test_a_request_is_pinned_to_the_signed_in_users_workspace(): void
    {
        $mine = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $mine->id]);

        $this->actingAs($user)->get('/calendar')->assertOk();

        $this->assertSame($mine->id, app(WorkspaceContext::class)->id());
    }

    public function test_scoping_can_be_suspended_deliberately(): void
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();

        Post::factory()->create(['workspace_id' => $mine->id]);
        Post::factory()->create(['workspace_id' => $theirs->id]);

        $context = app(WorkspaceContext::class);
        $context->set($mine->id);

        // The scheduler sweeps due targets across every workspace. That is the
        // one legitimate reason to step outside the scope.
        $total = $context->runUnscoped(fn () => Post::query()->count());

        $this->assertSame(2, $total);
        $this->assertSame($mine->id, $context->id(), 'The previous workspace should be restored.');
    }

    protected function tearDown(): void
    {
        app(WorkspaceContext::class)->forget();

        parent::tearDown();
    }
}
