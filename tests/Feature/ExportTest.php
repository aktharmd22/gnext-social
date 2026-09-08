<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InsightWindow;
use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Models\Post;
use App\Models\PostInsight;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sheet stops being the source of truth and becomes a report.
 */
class ExportTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    private User $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create(['timezone' => 'Asia/Dubai']);

        $this->admin = User::factory()->admin()->create([
            'workspace_id' => $this->workspace->id,
            'timezone' => 'Asia/Dubai',
        ]);

        $this->writer = User::factory()->create([
            'workspace_id' => $this->workspace->id,
            'timezone' => 'Asia/Dubai',
        ]);
    }

    private function publishedPost(?User $author = null): Post
    {
        $account = SocialAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Spark Tires',
        ]);

        $post = Post::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => ($author ?? $this->admin)->id,
            'title' => 'Back-to-school range',
            'caption' => 'The new range is in store now.',
            'status' => PostStatus::Published,
            'published_at' => now()->subDay(),
        ]);

        PostMedia::factory()->create([
            'post_id' => $post->id,
            'source_url' => 'https://drive.google.com/file/d/1A2b3C4d5E6f7G8h/view',
        ]);

        $target = PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'status' => TargetStatus::Published,
            'permalink' => 'https://facebook.com/999',
            'published_at' => now()->subDay(),
        ]);

        PostInsight::create([
            'post_target_id' => $target->id,
            'window' => InsightWindow::Day,
            'captured_at' => now(),
            'reach' => 4200,
            'impressions' => 5600,
            'likes' => 180,
            'comments' => 12,
            'shares' => 6,
            'saves' => 22,
            'engagement_rate' => 5.2381,
        ]);

        return $post;
    }

    public function test_an_xlsx_export_downloads(): void
    {
        $this->publishedPost();

        $response = $this->actingAs($this->admin)->get('/exports/posts.xlsx');

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $response->headers->get('content-type')
        );
    }

    public function test_a_csv_export_carries_the_outcome_the_sheet_never_knew(): void
    {
        $this->publishedPost();

        $response = $this->actingAs($this->admin)->get('/exports/posts.csv');
        $response->assertOk();

        $csv = $response->streamedContent();

        // The original sheet's shape.
        $this->assertStringContainsString('Graphic Link', $csv);
        $this->assertStringContainsString('Content', $csv);

        // And what a spreadsheet could never know.
        $this->assertStringContainsString('Permalink', $csv);
        $this->assertStringContainsString('https://facebook.com/999', $csv);
        $this->assertStringContainsString('Spark Tires', $csv);
        $this->assertStringContainsString('4200', $csv);
    }

    public function test_an_unknown_format_is_not_a_route(): void
    {
        $this->actingAs($this->admin)->get('/exports/posts.pdf')->assertNotFound();
    }

    /**
     * A user exports their own work; an admin exports everyone's. Filtered at
     * the query rather than denied outright, so the button does not simply
     * vanish for half the team.
     */
    public function test_a_writer_exports_only_their_own_posts(): void
    {
        $this->publishedPost($this->admin);
        $this->publishedPost($this->writer);

        $mine = $this->actingAs($this->writer)->get('/exports/posts.csv')->streamedContent();
        $rows = substr_count(trim($mine), "\n");

        // Header plus exactly one row.
        $this->assertSame(1, $rows, 'A writer should see only their own post.');

        $all = $this->actingAs($this->admin)->get('/exports/posts.csv')->streamedContent();
        $this->assertSame(2, substr_count(trim($all), "\n"), 'An admin should see both.');
    }

    public function test_the_export_honours_a_status_filter(): void
    {
        $this->publishedPost();

        Post::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->admin->id,
            'caption' => 'This one is still a draft and should not appear.',
            'status' => PostStatus::Draft,
        ]);

        $csv = $this->actingAs($this->admin)
            ->get('/exports/posts.csv?s[]=published')
            ->streamedContent();

        // The internal title is deliberately absent: it is never published, so
        // it has no place in a report a client might read. The caption is what
        // identifies a row.
        $this->assertStringContainsString('The new range is in store now.', $csv);
        $this->assertStringNotContainsString('still a draft', $csv);
    }

    public function test_a_pdf_content_calendar_is_produced(): void
    {
        $this->publishedPost();

        $response = $this->actingAs($this->admin)->get('/exports/calendar.pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));

        // A real PDF, not an error page rendered as one.
        // Pdf::download() returns a plain response, not a streamed one.
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_a_guest_cannot_export_anything(): void
    {
        $this->get('/exports/posts.csv')->assertRedirect('/login');
        $this->get('/exports/calendar.pdf')->assertRedirect('/login');
    }
}
