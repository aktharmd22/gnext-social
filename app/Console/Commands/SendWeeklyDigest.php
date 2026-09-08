<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Workspace;
use App\Notifications\WeeklyDigestNotification;
use App\Services\NotificationDispatcher;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;

/**
 * Weekly. What published, what is scheduled, and -- the useful part -- which
 * days next week are empty.
 */
class SendWeeklyDigest extends Command
{
    protected $signature = 'gnext:weekly-digest {--dry : Print the digest without sending it}';

    protected $description = 'Send the weekly summary of what published and what is coming';

    public function handle(NotificationDispatcher $dispatcher, WorkspaceContext $context): int
    {
        $workspaces = Workspace::query()->where('is_active', true)->get();

        foreach ($workspaces as $workspace) {
            $summary = $context->runFor($workspace->id, fn () => $this->summarise($workspace));

            $this->line(sprintf(
                '%s: %d published, %d scheduled, %d empty %s next week.',
                $workspace->name,
                $summary['published'],
                $summary['scheduled'],
                count($summary['empty_days']),
                str('day')->plural(count($summary['empty_days']))
            ));

            if ($this->option('dry')) {
                continue;
            }

            $notification = new WeeklyDigestNotification($workspace, $summary);

            $dispatcher->send($workspace, $notification, $notification->toPlainText());
        }

        return self::SUCCESS;
    }

    /**
     * @return array{published: int, failed: int, scheduled: int, empty_days: list<string>, top: ?Post}
     */
    private function summarise(Workspace $workspace): array
    {
        $tz = $workspace->timezone;

        $lastWeekStart = now($tz)->subWeek()->startOfWeek();
        $lastWeekEnd = now($tz)->subWeek()->endOfWeek();

        $nextWeekStart = now($tz)->addWeek()->startOfWeek();
        $nextWeekEnd = now($tz)->addWeek()->endOfWeek();

        $published = Post::query()
            ->whereBetween('published_at', [$lastWeekStart->copy()->utc(), $lastWeekEnd->copy()->utc()])
            ->whereIn('status', [PostStatus::Published->value, PostStatus::PartiallyPublished->value])
            ->count();

        $failed = Post::query()
            ->whereBetween('scheduled_at', [$lastWeekStart->copy()->utc(), $lastWeekEnd->copy()->utc()])
            ->where('status', PostStatus::Failed->value)
            ->count();

        $upcoming = Post::query()
            ->whereBetween('scheduled_at', [$nextWeekStart->copy()->utc(), $nextWeekEnd->copy()->utc()])
            ->get(['id', 'scheduled_at']);

        $busyDays = $upcoming
            ->map(fn (Post $post) => $post->scheduled_at->copy()->setTimezone($tz)->toDateString())
            ->unique()
            ->all();

        $emptyDays = [];

        for ($day = $nextWeekStart->copy(); $day->lte($nextWeekEnd); $day->addDay()) {
            if (! in_array($day->toDateString(), $busyDays, true)) {
                $emptyDays[] = $day->format('D j M');
            }
        }

        // Best performing by engagement rate, of anything measured last week.
        $top = Post::query()
            ->whereBetween('published_at', [$lastWeekStart->copy()->utc(), $lastWeekEnd->copy()->utc()])
            ->with(['targets.insights'])
            ->get()
            ->sortByDesc(fn (Post $post) => $post->targets
                ->flatMap->insights
                ->max('engagement_rate') ?? 0)
            ->first();

        return [
            'published' => $published,
            'failed' => $failed,
            'scheduled' => $upcoming->count(),
            'empty_days' => $emptyDays,
            'top' => $top,
        ];
    }
}
