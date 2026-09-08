<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Post;
use App\Models\PostTarget;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The original spreadsheet's columns, plus everything the spreadsheet could
 * never know.
 *
 * That is the whole point of this export: the sheet stops being the source of
 * truth and becomes a report. It gains where each post went, whether it
 * actually published, its permalink, and how it performed.
 *
 * One row per destination rather than per post, because a post that succeeded
 * on Facebook and failed on Instagram has two different outcomes to report.
 */
class PostsExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    /**
     * @param  Collection<int, Post>  $posts
     */
    public function __construct(
        private readonly Collection $posts,
        private readonly string $timezone,
    ) {}

    /**
     * @return Collection<int, array{post: Post, target: ?PostTarget}>
     */
    public function collection(): Collection
    {
        $rows = collect();

        foreach ($this->posts as $post) {
            if ($post->targets->isEmpty()) {
                $rows->push(['post' => $post, 'target' => null]);

                continue;
            }

            foreach ($post->targets as $target) {
                $rows->push(['post' => $post, 'target' => $target]);
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            // The shape the original sheet used, so it stays familiar.
            'Date',
            'Day',
            'Time',
            'Type',
            'Status',
            'Graphic Link',
            'Content',
            // Everything a spreadsheet could not know.
            'Platform',
            'Account',
            'Outcome',
            'Permalink',
            'Published at',
            'Error',
            'Reach',
            'Impressions',
            'Engagements',
            'Engagement rate %',
        ];
    }

    /**
     * @param  array{post: Post, target: ?PostTarget}  $row
     * @return list<string|int|float|null>
     */
    public function map($row): array
    {
        $post = $row['post'];
        $target = $row['target'];

        $local = $post->scheduled_at?->copy()->setTimezone($this->timezone);
        $published = $target?->published_at?->copy()->setTimezone($this->timezone);

        // The best window we have: 30d if captured, else 7d, else 24h.
        $insight = $target?->insights
            ->sortByDesc(fn ($i) => $i->window->hoursAfterPublish())
            ->first();

        return [
            $local?->format('d-m-Y'),
            $local?->format('l'),
            $local?->format('H:i'),
            $post->type->label(),
            $post->status->label(),
            $post->media->first()?->source_url,
            $post->caption,

            $target?->socialAccount?->platform->label(),
            $target?->socialAccount?->name,
            $target?->status->label(),
            $target?->permalink,
            $published?->format('d-m-Y H:i'),
            $target?->error_message,

            $insight?->reach,
            $insight?->impressions,
            $insight?->totalEngagements(),
            $insight?->engagement_rate,
        ];
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
