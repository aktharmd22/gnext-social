<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The outcome of one post at one destination.
 *
 * This is the source of truth. A post row summarises these; it never overrides
 * them. One caption going to a Facebook Page and an Instagram account produces
 * two rows that succeed or fail independently.
 */
enum TargetStatus: string
{
    case Queued = 'queued';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Publishing => 'Publishing',
            self::Published => 'Published',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }

    public function glyph(): string
    {
        return match ($this) {
            self::Queued => '◷',
            self::Publishing => '◍',
            self::Published => '●',
            self::Failed => '✕',
            self::Skipped => '⊘',
        };
    }

    public function token(): string
    {
        return match ($this) {
            self::Queued => 'scheduled',
            self::Publishing => 'publishing',
            self::Published => 'published',
            self::Failed => 'failed',
            self::Skipped => 'cancelled',
        };
    }

    /**
     * Whether a publish attempt may still be made against this target.
     */
    public function isPending(): bool
    {
        return in_array($this, [self::Queued, self::Publishing], true);
    }

    public function isRetryable(): bool
    {
        return $this === self::Failed;
    }
}
