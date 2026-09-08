<?php

declare(strict_types=1);

namespace App\Enums;

enum PostSource: string
{
    case Manual = 'manual';
    case Import = 'import';
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Created here',
            self::Import => 'Imported',
            self::Duplicate => 'Duplicated',
        };
    }
}
