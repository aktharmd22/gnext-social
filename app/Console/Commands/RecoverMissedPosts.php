<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TargetStatus;
use App\Jobs\PublishPostTargetJob;
use App\Models\PostTarget;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;

/**
 * Picks up anything that was due while nothing was running.
 *
 * Kill the worker for two hours and restart it: those posts publish, late,
 * rather than vanishing. That is a stated requirement, and the difference
 * between a system you can trust overnight and one you cannot.
 *
 * A post published outside its window is flagged late rather than silently
 * treated as on time -- Post::wasPublishedLate() is what the UI reads.
 */
class RecoverMissedPosts extends Command
{
    protected $signature = 'gnext:recover-missed
                            {--hours= : How far back to look, defaults to the configured window}
                            {--dry : List what would be recovered without queueing anything}';

    protected $description = 'Publish anything that fell due while the worker was down';

    public function handle(WorkspaceContext $workspace): int
    {
        $hours = (int) ($this->option('hours') ?: config('gnext.publishing.recovery_window_hours', 6));
        $since = now()->subHours($hours);

        $recovered = $workspace->runUnscoped(function () use ($since, $hours): int {
            $targets = PostTarget::query()
                ->with(['post', 'socialAccount'])
                ->where('status', TargetStatus::Queued->value)
                ->where('attempts', '<', (int) config('gnext.publishing.max_attempts'))
                ->whereHas('post', function ($query) use ($since): void {
                    $query->due()->where('scheduled_at', '>=', $since);
                })
                ->orderBy('id')
                ->get();

            foreach ($targets as $target) {
                $late = $target->post?->scheduled_at?->diffForHumans(null, true);

                $this->warn(sprintf(
                    '  Late by %s: %s → %s',
                    $late ?? 'unknown',
                    $target->post?->title ?: 'post #'.$target->post_id,
                    $target->socialAccount?->displayName() ?? 'unknown account'
                ));

                if (! $this->option('dry')) {
                    PublishPostTargetJob::dispatch($target->id);
                }
            }

            if ($targets->isEmpty()) {
                $this->info(sprintf('Nothing missed in the last %d hours.', $hours));
            }

            return $targets->count();
        });

        if ($recovered > 0) {
            $this->info($recovered.' missed '.str('destination')->plural($recovered)
                .($this->option('dry') ? ' would be recovered.' : ' queued for immediate publishing.'));
        }

        return self::SUCCESS;
    }
}
