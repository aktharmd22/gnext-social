<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TargetStatus;
use App\Models\PostTarget;
use Illuminate\Support\Collection;

/**
 * When this audience is actually awake.
 *
 * Built from measured engagement rather than folklore, and fed back into the
 * composer as a hint. A hint, not a constraint: the operator knows about the
 * campaign launching on Thursday and the heatmap does not.
 */
class ScheduleSuggester
{
    /**
     * Engagement by local weekday and hour.
     *
     * Aggregated in PHP rather than SQL because the conversion to the viewer's
     * timezone has to happen before the grouping -- a post at 02:00 Dubai is a
     * different bucket from the same instant in UTC -- and the volumes here are
     * hundreds of rows, not millions.
     *
     * @return array{grid: array<int, array<int, array{posts: int, rate: float}>>, max: float, samples: int}
     */
    public function heatmap(string $timezone, int $lookbackDays = 180): array
    {
        $targets = PostTarget::query()
            ->with('insights')
            ->where('status', TargetStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '>=', now()->subDays($lookbackDays))
            ->get();

        // weekday (1 = Monday) => hour => running totals
        $grid = [];
        $max = 0.0;
        $samples = 0;

        foreach ($targets as $target) {
            $rate = $target->insights
                ->sortByDesc(fn ($i) => $i->window->hoursAfterPublish())
                ->first()?->engagement_rate;

            if ($rate === null) {
                continue;
            }

            $local = $target->published_at->copy()->setTimezone($timezone);
            $weekday = (int) $local->isoWeekday();
            $hour = (int) $local->format('G');

            $grid[$weekday][$hour]['posts'] = ($grid[$weekday][$hour]['posts'] ?? 0) + 1;
            $grid[$weekday][$hour]['total'] = ($grid[$weekday][$hour]['total'] ?? 0) + $rate;
            $samples++;
        }

        foreach ($grid as $weekday => $hours) {
            foreach ($hours as $hour => $bucket) {
                $rate = round($bucket['total'] / $bucket['posts'], 2);

                $grid[$weekday][$hour] = ['posts' => $bucket['posts'], 'rate' => $rate];
                $max = max($max, $rate);
            }
        }

        return ['grid' => $grid, 'max' => $max, 'samples' => $samples];
    }

    /**
     * The best few slots, as something the composer can offer in one click.
     *
     * @return Collection<int, array{weekday: int, hour: int, label: string, rate: float, posts: int}>
     */
    public function bestSlots(string $timezone, int $limit = 3): Collection
    {
        $heatmap = $this->heatmap($timezone);
        $slots = collect();

        foreach ($heatmap['grid'] as $weekday => $hours) {
            foreach ($hours as $hour => $bucket) {
                // One post is an anecdote, not a pattern.
                if ($bucket['posts'] < 2) {
                    continue;
                }

                $slots->push([
                    'weekday' => $weekday,
                    'hour' => $hour,
                    'label' => $this->weekdayName($weekday).' at '.sprintf('%02d:00', $hour),
                    'rate' => $bucket['rate'],
                    'posts' => $bucket['posts'],
                ]);
            }
        }

        return $slots->sortByDesc('rate')->take($limit)->values();
    }

    /**
     * Whether there is enough measured history to say anything useful.
     */
    public function hasEnoughData(string $timezone): bool
    {
        return $this->heatmap($timezone)['samples'] >= 5;
    }

    private function weekdayName(int $isoWeekday): string
    {
        return [
            1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
            5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
        ][$isoWeekday] ?? 'Unknown';
    }
}
