<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Support\Carbon;

/**
 * What the dry run decided about one spreadsheet row.
 */
final class RowVerdict
{
    public const READY = 'ready';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    /**
     * Three tiers, not two.
     *
     * `problems` stop the row importing. `warnings` are things worth a human
     * glance that import anyway. `notes` are purely informational and must not
     * colour the row at all -- a Drive link is the normal case, and marking
     * every such row "warning" would drown the rows that genuinely need
     * looking at.
     *
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $problems
     * @param  list<string>  $warnings
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly int $lineNumber,
        public readonly string $status,
        public readonly array $raw,
        public readonly array $problems = [],
        public readonly array $warnings = [],
        public readonly array $notes = [],
        public readonly ?Carbon $scheduledAt = null,
        public readonly ?string $caption = null,
        public readonly ?string $mediaUrl = null,
        public readonly ?string $type = null,
        public readonly ?string $title = null,
    ) {}

    public function isImportable(): bool
    {
        return $this->status !== self::ERROR;
    }

    /**
     * The reason column in the downloadable error report.
     */
    public function reason(): string
    {
        return implode(' ', array_merge($this->problems, $this->warnings, $this->notes));
    }
}
