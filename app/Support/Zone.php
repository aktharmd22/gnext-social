<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Human-readable timezone labels.
 *
 * Every displayed time carries its zone -- never a bare "9:00". PHP's own `T`
 * format returns "+04" for Asia/Dubai, because the IANA database carries no
 * abbreviation for it. "+04" is accurate but reads like a machine; the team
 * says GST, so that is what the interface says.
 *
 * Anything not listed falls back to the offset, which is always correct even
 * when it is not friendly.
 */
final class Zone
{
    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'Asia/Dubai' => 'GST',
        'Asia/Muscat' => 'GST',
        'Asia/Riyadh' => 'AST',
        'Asia/Qatar' => 'AST',
        'Asia/Kuwait' => 'AST',
        'Asia/Bahrain' => 'AST',
        'Asia/Karachi' => 'PKT',
        'Asia/Kolkata' => 'IST',
        'Asia/Calcutta' => 'IST',
        'UTC' => 'UTC',
    ];

    public static function label(?string $timezone): string
    {
        $timezone ??= (string) config('gnext.default_timezone');

        if (isset(self::LABELS[$timezone])) {
            return self::LABELS[$timezone];
        }

        // Europe/London and friends genuinely have abbreviations; use them
        // rather than an offset when one exists.
        $abbreviation = Carbon::now($timezone)->format('T');

        return str_starts_with($abbreviation, '+') || str_starts_with($abbreviation, '-')
            ? 'UTC'.$abbreviation
            : $abbreviation;
    }
}
