<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PublishPostTargetJob;
use App\Models\PostTarget;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;

/**
 * Runs every minute. Queues anything whose moment has arrived.
 *
 * Deliberately unscoped: it sweeps every workspace, which is the one place the
 * workspace scope is stepped outside on purpose. Each job re-establishes its
 * own workspace before touching anything.
 */
class DispatchDuePosts extends Command
{
    protected $signature = 'gnext:dispatch-due
                            {--dry : List what would be dispatched without queueing anything}';

    protected $description = 'Queue every post destination whose scheduled time has arrived';

    public function handle(WorkspaceContext $workspace): int
    {
        $dispatched = $workspace->runUnscoped(function (): int {
            $targets = PostTarget::query()
                ->with(['post', 'socialAccount'])
                ->dispatchable()
                ->orderBy('id')
                ->limit(500)
                ->get();

            foreach ($targets as $target) {
                $this->line(sprintf(
                    '  %s → %s (due %s)',
                    $target->post?->title ?: 'post #'.$target->post_id,
                    $target->socialAccount?->displayName() ?? 'unknown account',
                    $target->post?->scheduled_at?->format('Y-m-d H:i \U\T\C') ?? 'unknown'
                ));

                if (! $this->option('dry')) {
                    PublishPostTargetJob::dispatch($target->id);
                }
            }

            return $targets->count();
        });

        $this->info($dispatched === 0
            ? 'Nothing due.'
            : $dispatched.' '.str('destination')->plural($dispatched).($this->option('dry') ? ' would be queued.' : ' queued.'));

        return self::SUCCESS;
    }
}
