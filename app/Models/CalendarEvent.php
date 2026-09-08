<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CalendarEventKind;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A UAE calendar marker rendered quietly behind the month grid.
 *
 * is_approximate exists because Hijri dates are confirmed by moon sighting;
 * showing a provisional Eid as though it were fixed would be worse than showing
 * it as provisional.
 */
class CalendarEvent extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'name',
        'name_ar',
        'starts_on',
        'ends_on',
        'kind',
        'is_approximate',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'kind' => CalendarEventKind::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_approximate' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Events touching a date range, including those that merely overlap it.
     */
    public function scopeOverlapping(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query
            ->where('starts_on', '<=', $to)
            ->where('ends_on', '>=', $from);
    }

    public function coversDate(\DateTimeInterface $date): bool
    {
        return $this->starts_on->lte($date) && $this->ends_on->gte($date);
    }
}
