<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Suggests which spreadsheet column feeds which system field.
 *
 * A suggestion only. The operator confirms every mapping in step 2, because a
 * column called "Status" in someone's sheet might mean "approved internally"
 * rather than anything this system knows about.
 */
class ColumnMapper
{
    /**
     * System fields, in the order they are presented, with the header names
     * commonly used for each. The first list entry is the canonical name.
     *
     * @var array<string, array{label: string, required: bool, aliases: list<string>}>
     */
    public const FIELDS = [
        'date' => [
            'label' => 'Date',
            'required' => true,
            'aliases' => ['date', 'post date', 'publish date', 'scheduled date', 'day date', 'when'],
        ],
        'time' => [
            'label' => 'Time',
            'required' => false,
            'aliases' => ['time', 'post time', 'publish time', 'scheduled time', 'hour'],
        ],
        'caption' => [
            'label' => 'Caption',
            'required' => true,
            'aliases' => ['content', 'caption', 'copy', 'text', 'post', 'description', 'body', 'message'],
        ],
        'media_url' => [
            'label' => 'Media link',
            'required' => false,
            'aliases' => ['graphic link', 'graphic', 'image', 'image link', 'media', 'media link',
                'asset', 'creative', 'drive link', 'link', 'url', 'artwork'],
        ],
        'type' => [
            'label' => 'Type',
            'required' => false,
            'aliases' => ['type', 'content type', 'format', 'post type'],
        ],
        'platform' => [
            'label' => 'Platform',
            'required' => false,
            'aliases' => ['platform', 'channel', 'network', 'account'],
        ],
        'status' => [
            'label' => 'Status',
            'required' => false,
            'aliases' => ['status', 'state', 'stage', 'progress'],
        ],
        'title' => [
            'label' => 'Title',
            'required' => false,
            'aliases' => ['title', 'name', 'topic', 'subject', 'theme'],
        ],
    ];

    /**
     * Best guess at a mapping, as field => column index (or null).
     *
     * @param  list<string>  $headers
     * @return array<string, int|null>
     */
    public function suggest(array $headers): array
    {
        $normalised = array_map(fn (string $h) => $this->normalise($h), $headers);
        $mapping = [];
        $claimed = [];

        foreach (self::FIELDS as $field => $definition) {
            $best = null;
            $bestScore = 0;

            foreach ($normalised as $index => $header) {
                if ($header === '' || in_array($index, $claimed, true)) {
                    continue;
                }

                $score = $this->score($header, $definition['aliases']);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $index;
                }
            }

            // 80 is high enough that "Day" does not claim the "Date" column,
            // and low enough that "Graphic Link " still matches "graphic link".
            if ($best !== null && $bestScore >= 80) {
                $mapping[$field] = $best;
                $claimed[] = $best;
            } else {
                $mapping[$field] = null;
            }
        }

        return $mapping;
    }

    /**
     * @param  list<string>  $aliases
     */
    private function score(string $header, array $aliases): int
    {
        $best = 0;

        foreach ($aliases as $alias) {
            if ($header === $alias) {
                return 100;
            }

            // A header that contains the alias as a whole word, e.g.
            // "graphic link (drive)".
            if (str_contains($header, $alias)) {
                $best = max($best, 92);

                continue;
            }

            similar_text($header, $alias, $percent);
            $best = max($best, (int) round($percent));
        }

        return $best;
    }

    private function normalise(string $header): string
    {
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9 ]+/', ' ', $header) ?? $header;
        $header = preg_replace('/\s+/', ' ', $header) ?? $header;

        return trim($header);
    }

    /**
     * @param  array<string, int|null>  $mapping
     * @return list<string> names of required fields left unmapped
     */
    public function missingRequired(array $mapping): array
    {
        $missing = [];

        foreach (self::FIELDS as $field => $definition) {
            if ($definition['required'] && ($mapping[$field] ?? null) === null) {
                $missing[] = $definition['label'];
            }
        }

        return $missing;
    }
}
