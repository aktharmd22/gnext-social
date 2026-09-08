<?php

declare(strict_types=1);

namespace App\Enums;

enum InsightWindow: string
{
    case Day = '24h';
    case Week = '7d';
    case Month = '30d';

    public function label(): string
    {
        return match ($this) {
            self::Day => 'First 24 hours',
            self::Week => 'First 7 days',
            self::Month => 'First 30 days',
        };
    }

    /**
     * How long after publication this window is captured.
     */
    public function hoursAfterPublish(): int
    {
        return match ($this) {
            self::Day => 24,
            self::Week => 24 * 7,
            self::Month => 24 * 30,
        };
    }
}
