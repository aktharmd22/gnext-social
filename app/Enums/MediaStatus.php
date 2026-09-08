<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a media row is in the ingestion pipeline.
 *
 * A post may never reach Scheduled while any of its media is not Ready. Meta
 * fetches the bytes itself at publish time, so an unresolved Drive link is a
 * guaranteed failure at 09:00 rather than a warning in the composer.
 */
enum MediaStatus: string
{
    case Pending = 'pending';
    case Fetching = 'fetching';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting to fetch',
            self::Fetching => 'Fetching',
            self::Ready => 'Ready',
            self::Failed => 'Could not fetch',
        };
    }

    public function token(): string
    {
        return match ($this) {
            self::Pending => 'draft',
            self::Fetching => 'publishing',
            self::Ready => 'published',
            self::Failed => 'failed',
        };
    }

    public function isReady(): bool
    {
        return $this === self::Ready;
    }
}
