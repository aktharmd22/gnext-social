<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InsightWindow;
use App\Services\InsightsCollector;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;

/**
 * Hourly. Captures the 24-hour, 7-day and 30-day numbers as each window falls
 * due, so they stay comparable between posts.
 */
class CaptureInsights extends Command
{
    protected $signature = 'gnext:capture-insights {--window= : Only this window (24h, 7d or 30d)}';

    protected $description = 'Pull performance metrics for posts whose measurement window has arrived';

    public function handle(InsightsCollector $collector, WorkspaceContext $workspace): int
    {
        $windows = $this->option('window') !== null
            ? array_filter([InsightWindow::tryFrom((string) $this->option('window'))])
            : InsightWindow::cases();

        if ($windows === []) {
            $this->error('Unknown window. Use 24h, 7d or 30d.');

            return self::FAILURE;
        }

        $captured = 0;

        $workspace->runUnscoped(function () use ($collector, $windows, &$captured): void {
            foreach ($windows as $window) {
                $targets = $collector->due($window);

                foreach ($targets as $target) {
                    $workspaceId = $target->post?->workspace_id;

                    if ($workspaceId === null) {
                        continue;
                    }

                    $insight = app(WorkspaceContext::class)->runFor(
                        $workspaceId,
                        fn () => $collector->capture($target, $window)
                    );

                    if ($insight !== null) {
                        $captured++;

                        $this->line(sprintf(
                            '  %s [%s] reach %s',
                            $target->post?->title ?: 'post #'.$target->post_id,
                            $window->value,
                            number_format((int) $insight->reach)
                        ));
                    }
                }
            }
        });

        $this->info($captured === 0
            ? 'No new metrics were available.'
            : $captured.' '.str('measurement')->plural($captured).' captured.');

        return self::SUCCESS;
    }
}
