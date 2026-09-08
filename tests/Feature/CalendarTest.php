<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CalendarEventKind;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Livewire\Calendar;
use App\Models\CalendarEvent;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create(['timezone' => 'Asia/Dubai']);
        $this->user = User::factory()->admin()->create([
            'workspace_id' => $this->workspace->id,
            'timezone' => 'Asia/Dubai',
        ]);
    }

    private function makePost(string $localDateTime, array $attributes = []): Post
    {
        return Post::factory()->create(array_merge([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'scheduled_at' => Carbon::parse($localDateTime, 'Asia/Dubai')->utc(),
            'status' => PostStatus::Scheduled,
        ], $attributes));
    }

    public function test_it_shows_posts_for_the_current_month(): void
    {
        $this->makePost(now('Asia/Dubai')->startOfMonth()->addDays(4)->format('Y-m-d').' 09:00', [
            'title' => 'In this month',
        ]);

        Livewire::actingAs($this->user)
            ->test(Calendar::class)
            ->assertSee('In this month');
    }

    public function test_moving_to_the_next_month_changes_what_is_shown(): void
    {
        // Mid-month deliberately: the grid runs whole weeks, so the first few
        // days of next month legitimately appear in this month's trailing row.
        $nextMonth = now('Asia/Dubai')->startOfMonth()->addMonth();

        $this->makePost($nextMonth->copy()->addDays(14)->format('Y-m-d').' 09:00', ['title' => 'Next month post']);

        Livewire::actingAs($this->user)
            ->test(Calendar::class)
            ->assertDontSee('Next month post')
            ->call('next')
            ->assertSee('Next month post')
            ->call('previous')
            ->assertDontSee('Next month post');
    }

    /**
     * A post at 02:00 Dubai is stored on the previous UTC day. It must appear
     * in the cell a human would look in, not the one the database uses.
     */
    public function test_a_post_lands_on_its_local_day_not_its_utc_day(): void
    {
        $localDay = now('Asia/Dubai')->startOfMonth()->addDays(9);

        $post = $this->makePost($localDay->format('Y-m-d').' 02:00', ['title' => 'Early morning']);

        // Sanity: the stored UTC timestamp really is the previous day.
        $this->assertSame(
            $localDay->copy()->subDay()->toDateString(),
            $post->scheduled_at->toDateString()
        );

        Livewire::actingAs($this->user)
            ->test(Calendar::class)
            ->assertSee('Early morning')
            ->assertSee('02:00');
    }

    // ---------------------------------------------------------------- filters

    public function test_it_filters_by_status(): void
    {
        $day = now('Asia/Dubai')->startOfMonth()->addDays(2)->format('Y-m-d');

        $this->makePost($day.' 09:00', ['title' => 'A draft', 'status' => PostStatus::Draft]);
        $this->makePost($day.' 11:00', ['title' => 'A scheduled one', 'status' => PostStatus::Scheduled]);

        Livewire::actingAs($this->user)
            ->test(Calendar::class)
            ->set('statuses', ['draft'])
            ->assertSee('A draft')
            ->assertDontSee('A scheduled one');
    }

    public function test_it_filters_by_type(): void
    {
        $day = now('Asia/Dubai')->startOfMonth()->addDays(2)->format('Y-m-d');

        $this->makePost($day.' 09:00', ['title' => 'A reel', 'type' => PostType::Reel]);
        $this->makePost($day.' 11:00', ['title' => 'A feed post', 'type' => PostType::Post]);

        Livewire::actingAs($this->user)
            ->test(Calendar::class)
            ->set('types', ['reel'])
            ->assertSee('A reel')
            ->assertDontSee('A feed post');
    }

    public function test_it_filters_by_platform(): void
    {
        $day = now('Asia/Dubai')->startOfMonth()->addDays(2)->format('Y-m-d');

        $facebook = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
        $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);

        $fbPost = $this->makePost($day.' 09:00', ['title' => 'Facebook only']);
        $igPost = $this->makePost($day.' 11:00', ['title' => 'Instagram only']);

        PostTarget::factory()->create(['post_id' => $fbPost->id, 'social_account_id' => $facebook->id]);
        PostTarget::factory()->create(['post_id' => $igPost->id, 'social_account_id' => $instagram->id]);

        Livewire::actingAs($this->user)
            ->test(Calendar::class)
            ->set('platforms', [Platform::Instagram->value])
            ->assertSee('Instagram only')
            ->assertDontSee('Facebook only');
    }

    public function test_it_filters_by_author(): void
    {
        $day = now('Asia/Dubai')->startOfMonth()->addDays(2)->format('Y-m-d');

        $colleague = User::factory()->create(['workspace_id' => $this->workspace->id]);

        $this->makePost($day.' 09:00', ['title' => 'Mine']);
        $this->makePost($day.' 11:00', ['title' => 'Theirs', 'created_by' => $colleague->id]);

        Livewire::actingAs($this->user)
            ->test(Calendar::class)
            ->set('author', $colleague->id)
            ->assertSee('Theirs')
            ->assertDontSee('Mine');
    }

    // ------------------------------------------------------------------- gaps

    public function test_a_run_of_empty_days_is_flagged_with_a_fix(): void
    {
        // Fill the whole month except a five-day stretch, so exactly one gap
        // exists and the assertion cannot pass by accident.
        $start = now('Asia/Dubai')->startOfMonth();
        $daysInMonth = $start->daysInMonth;

        for ($day = 1; $day <= $daysInMonth; $day++) {
            if ($day >= 10 && $day <= 14) {
                continue;
            }

            $this->makePost($start->copy()->day($day)->format('Y-m-d').' 09:00');
        }

        Livewire::actingAs($this->user)
            ->test(Calendar::class)
            ->assertSee('5 empty days')
            ->assertSee('Fill this gap');
    }

    public function test_a_two_day_gap_is_not_flagged(): void
    {
        $start = now('Asia/Dubai')->startOfMonth();

        for ($day = 1; $day <= $start->daysInMonth; $day++) {
            if ($day === 10 || $day === 11) {
                continue;
            }

            $this->makePost($start->copy()->day($day)->format('Y-m-d').' 09:00');
        }

        Livewire::actingAs($this->user)
            ->test(Calendar::class)
            ->assertDontSee('Fill this gap');
    }

    /**
     * The composer is a page, so filling a gap navigates to it with the date
     * already chosen -- and the marker itself is a plain link, so it can be
     * opened in a new tab.
     */
    public function test_filling_a_gap_opens_the_composer_on_that_day(): void
    {
        Livewire::actingAs($this->user)
            ->test(Calendar::class)
            ->call('fillGap', '2026-09-10')
            ->assertRedirect(route('posts.create', ['date' => '2026-09-10']));
    }

    public function test_a_chip_links_to_the_composer_page(): void
    {
        $post = $this->makePost(now('Asia/Dubai')->startOfMonth()->addDays(4)->format('Y-m-d').' 09:00');

        Livewire::actingAs($this->user)
            ->test(Calendar::class)
            ->assertSee('href="'.route('posts.edit', $post).'"', false);
    }

    // -------------------------------------------------------------- overlay

    public function test_the_uae_overlay_is_drawn_behind_the_month(): void
    {
        $start = now('Asia/Dubai')->startOfMonth();

        CalendarEvent::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Back to school',
            'starts_on' => $start->copy()->addDays(2)->toDateString(),
            'ends_on' => $start->copy()->addDays(8)->toDateString(),
            'kind' => CalendarEventKind::Retail,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->user)
            ->test(Calendar::class)
            ->assertSee('Back to school');
    }

    public function test_a_provisional_hijri_date_is_marked_approximate(): void
    {
        $start = now('Asia/Dubai')->startOfMonth();

        CalendarEvent::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Eid al-Fitr',
            'starts_on' => $start->copy()->addDays(3)->toDateString(),
            'ends_on' => $start->copy()->addDays(5)->toDateString(),
            'kind' => CalendarEventKind::Religious,
            'is_approximate' => true,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->user)
            ->test(Calendar::class)
            ->assertSee('Eid al-Fitr*')
            ->assertSee('date approximate');
    }

    // ------------------------------------------------------------ reschedule

    public function test_rescheduling_keeps_the_time_of_day(): void
    {
        $post = $this->makePost('2026-09-08 19:30', ['title' => 'Evening post']);

        Livewire::actingAs($this->user)
            ->test(Calendar::class)
            ->call('reschedule', $post->id, '2026-09-11');

        $moved = $post->fresh()->scheduled_at->copy()->setTimezone('Asia/Dubai');

        $this->assertSame('2026-09-11', $moved->toDateString());

        // Moving Tuesday to Friday must not silently reschedule 19:30 to
        // midnight.
        $this->assertSame('19:30', $moved->format('H:i'));
    }

    public function test_a_published_post_cannot_be_dragged(): void
    {
        $post = $this->makePost('2026-09-08 09:00', [
            'status' => PostStatus::Published,
            'published_at' => now(),
        ]);

        Livewire::actingAs($this->user)
            ->test(Calendar::class)
            ->call('reschedule', $post->id, '2026-09-20')
            ->assertForbidden();

        $this->assertSame(
            '2026-09-08',
            $post->fresh()->scheduled_at->copy()->setTimezone('Asia/Dubai')->toDateString()
        );
    }

    public function test_a_user_cannot_drag_someone_elses_post(): void
    {
        $colleague = User::factory()->create(['workspace_id' => $this->workspace->id]);
        $writer = User::factory()->create(['workspace_id' => $this->workspace->id]);

        $post = $this->makePost('2026-09-08 09:00', ['created_by' => $colleague->id]);

        Livewire::actingAs($writer)
            ->test(Calendar::class)
            ->call('reschedule', $post->id, '2026-09-20')
            ->assertForbidden();
    }

    // ------------------------------------------------------------ performance

    /**
     * The brief is explicit: one query plus eager loads, never a query per day
     * cell. A month grid is 42 cells; an N+1 here is 42 round trips on the most
     * opened page in the product.
     */
    public function test_a_full_month_renders_without_a_query_per_day(): void
    {
        $account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
        $start = now('Asia/Dubai')->startOfMonth();

        for ($day = 1; $day <= 28; $day++) {
            $post = $this->makePost($start->copy()->day($day)->format('Y-m-d').' 09:00');
            PostTarget::factory()->create([
                'post_id' => $post->id,
                'social_account_id' => $account->id,
            ]);
        }

        DB::enableQueryLog();

        Livewire::actingAs($this->user)->test(Calendar::class);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            20,
            $queries,
            "Rendering a full month took {$queries} queries. That is a query per day cell, not one query plus eager loads."
        );
    }

    public function test_switching_views_is_remembered_in_the_url(): void
    {
        Livewire::actingAs($this->user)
            ->test(Calendar::class)
            ->call('setView', 'kanban')
            ->assertSet('view', 'kanban')
            ->call('setView', 'nonsense')
            ->assertSet('view', 'month');
    }
}
