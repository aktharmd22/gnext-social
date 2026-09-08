<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CaptionTemplate extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'name',
        'body',
        'is_footer',
        'language',
    ];

    protected function casts(): array
    {
        return [
            'is_footer' => 'boolean',
        ];
    }

    public function scopeFooters(Builder $query): Builder
    {
        return $query->where('is_footer', true);
    }
}
