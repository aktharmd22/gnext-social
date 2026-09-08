<?php

declare(strict_types=1);

namespace App\Enums;

enum ImportBatchStatus: string
{
    case Uploaded = 'uploaded';
    case Previewing = 'previewing';
    case Importing = 'importing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Uploaded',
            self::Previewing => 'Previewing',
            self::Importing => 'Importing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    public function isUndoable(): bool
    {
        return $this === self::Completed;
    }
}
