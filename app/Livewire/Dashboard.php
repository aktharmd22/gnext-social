<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\MediaStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Models\ActivityLog;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\Publishing\PublishOrchestrator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The landing screen: the state of the whole operation on one page.
 *
 * Every number here is a link. A dashboard that only reports is a wall
 * decoration -- the point is to see "3 failed" and be one click from fixing
 * them, so each tile, row and bar goes somewhere with the right filter applied.
 *
 * Local time is the workspace timezone throughout. Timestamps are stored UTC,
 * so every bucket boundary is converted before grouping: a post at 02:00 Dubai
 * belongs to that Dubai day, not to the previous UTC one.
 */
#[Layout('components.layouts.app')]
class Dashboard extends Component
{
    /** Days of publishing history in the activity chart. */
    public int $chartDays = 14;

    public function setChartDays(int $days): void
    {
        $this->chartDays = in_array($days, [7, 14, 30], true) ? $days : 14;
    }

    // ---------------------------------------------------------------- render

    public function render()
    {
        $user = auth()->user();
        $tz = $user->displayTimezone();
        $now = now();
        $today = $now->copy()->setTimezone($tz);

        return view('livewire.dashboard', [
            'tz' => $tz,
            'today' => $today,
            'greeting' => $this->greeting($today),
            'firstName' => str($user->name)->explode(' ')->first(),

            'tiles' => $this->tiles($now),
            'chart' => $this->chart($tz, $today),
            'upcoming' => $this->upcoming($now),
            'attention' => $this->attention($now, $tz),
            'pipeline' => $this->pipeline($tz, $today),
            'coverage' => $this->coverage($tz, $today),
            'platforms' => $this->platformSplit($now),
            'accounts' => $this->accounts(),
            'activity' => $this->recentActivity(),
        ]);
    }

    private function greeting(Carbon $localNow): string
    {
        return match (true) {
            $localNow->hour < 12 => 'Good morning',
            $localNow->hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    // ----------------------------------------------------------------- tiles

    /**
     * The four numbers worth interrupting someone for.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tiles(Carbon $now): array
    {
        $goingOut = Post::query()
            ->status(PostStatus::Scheduled, PostStatus::Approved)
            ->whereBetween('scheduled_at', [$now, $now->copy()->addDays(7)])
            ->count();

        $awaiting = Post::query()->awaitingApproval()->count();

        $published = Post::query()
            ->status(PostStatus::Published, PostStatus::PartiallyPublished)
            ->where('published_at', '>=', $now->copy()->subDays(30))
            ->count();

        // The same window immediately before, so the headline number carries a
        // direction as well as a value. "4 published" says nothing on its own.
        $publishedBefore = Post::query()
            ->status(PostStatus::Published, PostStatus::PartiallyPublished)
            ->whereBetween('published_at', [$now->copy()->subDays(60), $now->copy()->subDays(30)])
            ->count();

        // "Needs attention" is a union, not a status: something failed, or
        // something is late, or something will fail at 09:00 because its media
        // never resolved.
        $failed = Post::query()->status(PostStatus::Failed, PostStatus::PartiallyPublished)->count();
        $overdue = Post::query()->due()->count();

        return [
            [
                'key' => 'week',
                'label' => 'Going out this week',
                'value' => $goingOut,
                'token' => 'scheduled',
                'icon' => 'calendar',
                'note' => 'Scheduled or approved, next 7 days',
                'href' => route('calendar'),
            ],
            [
                'key' => 'approval',
                'label' => 'Awaiting approval',
                'value' => $awaiting,
                'token' => 'pending',
                'icon' => 'approvals',
                'note' => $awaiting > 0 ? 'Nothing publishes until decided' : 'Nothing waiting on you',
                'href' => route('approvals'),
            ],
            [
                'key' => 'published',
                'label' => 'Published',
                'value' => $published,
                'token' => 'published',
                'icon' => 'insights',
                'note' => 'In the last 30 days',
                'href' => route('insights'),
                'delta' => $published - $publishedBefore,
                'deltaNote' => 'vs the 30 days before',
            ],
            [
                'key' => 'attention',
                'label' => 'Needs attention',
                'value' => $failed + $overdue,
                'token' => ($failed + $overdue) > 0 ? 'failed' : 'draft',
                'icon' => 'warning',
                'note' => ($failed + $overdue) > 0
                    ? "{$failed} failed · {$overdue} overdue"
                    : 'Nothing failed or late',
                'href' => route('posts.index', ['status' => 'failed']),
            ],
        ];
    }

    // ----------------------------------------------------------------- chart

    /**
     * Posts published per local day.
     *
     * One query, grouped in PHP after conversion to the workspace timezone --
     * grouping in SQL would bucket by UTC day and quietly move every late-night
     * post to the day before.
     *
     * @return array<string, mixed>
     */
    private function chart(string $tz, Carbon $today): array
    {
        $start = $today->copy()->subDays($this->chartDays - 1)->startOfDay();

        $byDay = Post::query()
            ->status(PostStatus::Published, PostStatus::PartiallyPublished)
            ->whereNotNull('published_at')
            ->where('published_at', '>=', $start->copy()->utc())
            ->get(['id', 'published_at'])
            ->groupBy(fn (Post $post) => $post->published_at->copy()->setTimezone($tz)->toDateString());

        $days = collect(range(0, $this->chartDays - 1))->map(function (int $offset) use ($start, $byDay) {
            $day = $start->copy()->addDays($offset);
            $count = $byDay->get($day->toDateString())?->count() ?? 0;

            return [
                'date' => $day->toDateString(),
                'label' => $day->format('j M'),
                'weekday' => $day->format('D'),
                'count' => $count,
            ];
        });

        $peak = (int) $days->max('count');

        return [
            'days' => $days,
            'peak' => $peak,
            'total' => (int) $days->sum('count'),
            // Rounded up to an even tick with a floor of two, so the axis
            // labels are whole numbers and a single published post does not
            // become a full-height bar. Also keeps the zero case from
            // dividing by zero.
            'scale' => $peak <= 0 ? 2 : (int) max(2, ceil($peak / 2) * 2),
            'busiest' => $peak > 0 ? $days->firstWhere('count', $peak) : null,
        ];
    }

    // -------------------------------------------------------------- next up

    private function upcoming(Carbon $now): Collection
    {
        return Post::query()
            ->with(['targets.socialAccount', 'media'])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>=', $now)
            ->status(
                PostStatus::Draft,
                PostStatus::PendingApproval,
                PostStatus::Approved,
                PostStatus::Scheduled,
            )
            ->orderBy('scheduled_at')
            ->limit(7)
            ->get();
    }

    // ----------------------------------------------------- needs attention

    /**
     * Everything standing between the calendar and a clean week, newest first.
     *
     * Deliberately one flat list rather than four cards: an operator wants a
     * queue to work through, not a scavenger hunt across panels.
     *
     * @return array<int, array<string, mixed>>
     */
    private function attention(Carbon $now, string $tz): array
    {
        $items = [];

        $failed = Post::query()
            ->with('targets')
            ->status(PostStatus::Failed, PostStatus::PartiallyPublished)
            ->orderByDesc('scheduled_at')
            ->limit(4)
            ->get();

        foreach ($failed as $post) {
            $target = $post->targets->firstWhere('status', TargetStatus::Failed);

            $items[] = [
                'token' => 'failed',
                'title' => $post->title ?: 'Untitled post',
                'note' => $target?->error_message
                    ? str($target->error_message)->limit(70)
                    : 'Publishing failed at Meta.',
                'href' => route('posts.edit', $post),
                // A real action, not a link that merely opens the composer and
                // leaves the operator to work out what to do next.
                'retry' => $target?->id,
                'action' => 'Open',
            ];
        }

        $overdue = Post::query()->due()->orderBy('scheduled_at')->limit(3)->get();

        foreach ($overdue as $post) {
            $items[] = [
                'token' => 'partial',
                'title' => $post->title ?: 'Untitled post',
                'note' => 'Due '.$post->scheduled_at->copy()->setTimezone($tz)->diffForHumans().' and still queued.',
                'href' => route('posts.edit', $post),
                'action' => 'Open',
            ];
        }

        // Media that never resolved is the single most common cause of a 09:00
        // failure: Meta fetches the bytes itself, so an unresolved Drive link
        // is a guaranteed failure rather than a warning.
        $stuckMedia = PostMedia::query()
            ->with('post')
            ->where('status', MediaStatus::Failed->value)
            ->whereHas('post', fn ($q) => $q->whereIn('status', [
                PostStatus::Scheduled->value,
                PostStatus::Approved->value,
            ]))
            ->limit(3)
            ->get();

        foreach ($stuckMedia as $media) {
            $items[] = [
                'token' => 'pending',
                'title' => $media->post?->title ?: 'Untitled post',
                'note' => 'Media never downloaded. This will fail when it is due.',
                'href' => $media->post ? route('posts.edit', $media->post) : route('media.index'),
                'action' => 'Fix',
            ];
        }

        if (auth()->user()->can('view-tokens')) {
            $expiring = SocialAccount::query()
                ->active()
                ->whereNotNull('token_expires_at')
                ->where('token_expires_at', '<=', $now->copy()->addDays(max(config('gnext.tokens.warn_days'))))
                ->limit(3)
                ->get();

            foreach ($expiring as $account) {
                $days = $account->tokenExpiresInDays();

                $items[] = [
                    'token' => $account->tokenHasExpired() ? 'failed' : 'pending',
                    'title' => $account->displayName(),
                    'note' => $account->tokenHasExpired()
                        ? 'Token has expired. Publishing to this account is stopped.'
                        : "Token expires in {$days} day".($days === 1 ? '' : 's').'.',
                    'href' => route('settings.accounts'),
                    'action' => 'Reconnect',
                ];
            }
        }

        return $items;
    }

    /**
     * Publish one failed destination again, now.
     *
     * Admin only, and deliberately narrow: it re-queues a single destination
     * rather than the whole post, so a Facebook post that already went out is
     * never published twice to fix an Instagram failure.
     */
    public function retryTarget(int $targetId): void
    {
        abort_unless(auth()->user()->can('publish-now'), 403);

        $target = PostTarget::query()
            ->where('status', TargetStatus::Failed->value)
            ->findOrFail($targetId);

        app(PublishOrchestrator::class)->retry($target);

        $this->dispatch('toast', message: 'Retrying that destination now.');
    }

    // -------------------------------------------------------------- pipeline

    /**
     * This month's posts by status -- the shape of the work, not just its size.
     *
     * @return array<string, mixed>
     */
    private function pipeline(string $tz, Carbon $today): array
    {
        $counts = Post::query()
            ->whereBetween('scheduled_at', [
                $today->copy()->startOfMonth()->utc(),
                $today->copy()->endOfMonth()->utc(),
            ])
            ->get(['id', 'status'])
            ->countBy(fn (Post $post) => $post->status->value);

        $total = (int) $counts->sum();

        $rows = collect(PostStatus::cases())
            ->map(fn (PostStatus $status) => [
                'status' => $status,
                'count' => (int) ($counts[$status->value] ?? 0),
                'share' => $total > 0 ? ($counts[$status->value] ?? 0) / $total * 100 : 0,
            ])
            ->filter(fn (array $row) => $row['count'] > 0)
            ->sortByDesc('count')
            ->values();

        return ['rows' => $rows, 'total' => $total, 'month' => $today->format('F')];
    }

    // -------------------------------------------------------------- coverage

    /**
     * How much of this month actually has content on it.
     *
     * The same question the calendar's gap markers answer, summarised: a month
     * with 20 posts all in week one is not a covered month.
     *
     * @return array<string, mixed>
     */
    private function coverage(string $tz, Carbon $today): array
    {
        $start = $today->copy()->startOfMonth();
        $end = $today->copy()->endOfMonth();

        $filled = Post::query()
            ->whereBetween('scheduled_at', [$start->copy()->utc(), $end->copy()->endOfDay()->utc()])
            ->get(['id', 'scheduled_at'])
            ->map(fn (Post $post) => $post->scheduled_at->copy()->setTimezone($tz)->toDateString())
            ->unique()
            ->flip();

        // Blank cells so day 1 sits under its weekday, Monday-first exactly as
        // the calendar grid does. A ragged wrap of 30 squares reads as noise;
        // aligned weeks make a run of empty days visible at a glance, which is
        // the whole point.
        $lead = $start->dayOfWeekIso - 1;

        $days = collect(range(1, $end->day))->map(function (int $day) use ($start, $filled, $today) {
            $date = $start->copy()->day($day);

            return [
                'day' => $day,
                'date' => $date->toDateString(),
                'filled' => $filled->has($date->toDateString()),
                'past' => $date->lt($today->copy()->startOfDay()),
                'today' => $date->isSameDay($today),
            ];
        });

        $covered = $days->where('filled', true)->count();

        return [
            'days' => $days,
            'covered' => $covered,
            'total' => $days->count(),
            'percent' => $days->count() > 0 ? (int) round($covered / $days->count() * 100) : 0,
            'month' => $today->format('F Y'),
            'lead' => $lead,
        ];
    }

    // ------------------------------------------------------- platform split

    /**
     * Destinations published per platform in the last 30 days.
     *
     * Destinations, not posts: one post going to both platforms is two
     * publishes, and this panel is about where the work landed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function platformSplit(Carbon $now): array
    {
        $targets = PostTarget::query()
            ->with('socialAccount')
            ->where('status', TargetStatus::Published->value)
            // Filtered on the post's publish time, not the target's: a target
            // row is not always stamped, and a split that silently reports
            // nothing while the tile above reports four is worse than no panel.
            ->whereHas('post', fn ($q) => $q->where('published_at', '>=', $now->copy()->subDays(30)))
            ->get();

        $total = $targets->count();

        return collect(Platform::cases())
            ->map(function (Platform $platform) use ($targets, $total) {
                $count = $targets->filter(
                    fn (PostTarget $target) => $target->socialAccount?->platform === $platform
                )->count();

                return [
                    'platform' => $platform,
                    'count' => $count,
                    'share' => $total > 0 ? (int) round($count / $total * 100) : 0,
                ];
            })
            ->all();
    }

    // -------------------------------------------------------------- accounts

    private function accounts(): Collection
    {
        return SocialAccount::query()
            ->active()
            ->orderBy('platform')
            ->orderBy('name')
            ->get();
    }

    // -------------------------------------------------------------- activity

    private function recentActivity(): Collection
    {
        return ActivityLog::query()
            ->with('user')
            ->latest()
            ->limit(6)
            ->get();
    }
}
