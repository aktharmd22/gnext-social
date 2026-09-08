<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The UAE calendar overlay. Quiet background markers on the month grid, never
 * chips -- they are context for planning, not content.
 */
enum CalendarEventKind: string
{
    case Religious = 'religious';
    case National = 'national';
    case Retail = 'retail';
    case Season = 'season';

    public function label(): string
    {
        return match ($this) {
            self::Religious => 'Religious',
            self::National => 'National',
            self::Retail => 'Retail moment',
            self::Season => 'Season',
        };
    }
}
