<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\ScheduleSuggester;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * What published, how it did, and when this audience is actually awake.
 */
class Insights extends Component
{
    /** Days of history the whole dashboard covers. */
    #[Url(as: 'days')]
    public int $range = 90;

    /** Cells show a value as well as a shade: colour is never the only signal. */
    public bool $showTable = false;

    public function setRange(int $days): void
    {
        $this->range = in_array($days, [30, 90, 180, 365], true) ? $days : 90;
    }

    public function render(ScheduleSuggester $suggester)
    {
        $tz = auth()->user()->displayTimezone();
        $since = now()->subDays($this->range);

        $targets = PostTarget::query()
            ->with(['insights', 'post', 'socialAccount'])
            ->whereHas('post', fn ($q) => $q->where('scheduled_at', '>=', $since))
            ->get();

        $published = $targets->where('status', TargetStatus::Published);
        $failed = $targets->where('status', TargetStatus::Failed);
        $settled = $published->count() + $failed->count();

        // Posts, not destinations: "we published 14 things" is what a human means.
        $postsPublished = Post::query()
            ->whereIn('status', [PostStatus::Published->value, PostStatus::PartiallyPublished->value])
            ->where('published_at', '>=', $since)
            ->count();

        $rates = $published
            ->map(fn (PostTarget $t) => $t->insights
                ->sortByDesc(fn ($i) => $i->window->hoursAfterPublish())
                ->first()?->engagement_rate)
            ->filter();

        $best = $published
            ->filter(fn (PostTarget $t) => $t->insights->isNotEmpty())
            ->sortByDesc(fn (PostTarget $t) => $t->insights->max('engagement_rate') ?? 0)
            ->first();

        return view('livewire.insights', [
            'tz' => $tz,
            'postsPublished' => $postsPublished,
            'destinationsPublished' => $published->count(),
            'failedCount' => $failed->count(),
            // Reported only when something has actually settled: 100% of zero
            // is not a success rate, it is an absence of data.
            'successRate' => $settled > 0 ? round(($published->count() / $settled) * 100) : null,
            'medianRate' => $rates->isNotEmpty() ? round($rates->median(), 2) : null,
            'totalReach' => $published->sum(fn (PostTarget $t) => $t->insights->max('reach') ?? 0),
            'best' => $best,
            'bestRate' => $best?->insights->max('engagement_rate'),
            'heatmap' => $suggester->heatmap($tz, $this->range),
            'bestSlots' => $suggester->bestSlots($tz),
            'recentFailures' => $failed->sortByDesc('last_attempt_at')->take(5),
        ]);
    }
}
