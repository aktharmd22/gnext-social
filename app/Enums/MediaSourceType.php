<?php

declare(strict_types=1);

namespace App\Enums;

enum MediaSourceType: string
{
    case Upload = 'upload';
    case Drive = 'drive';
    case Url = 'url';

    public function label(): string
    {
        return match ($this) {
            self::Upload => 'Uploaded',
            self::Drive => 'Google Drive',
            self::Url => 'Link',
        };
    }

    /**
     * Whether the bytes still need to be fetched from somewhere else before
     * Meta can be given a URL it can actually read.
     */
    public function requiresFetch(): bool
    {
        return $this !== self::Upload;
    }
}
