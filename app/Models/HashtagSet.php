<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

class HashtagSet extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'name',
        'tags',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
        ];
    }

    /**
     * The set as it would be pasted into a caption.
     */
    public function toCaptionBlock(): string
    {
        return collect($this->tags ?? [])
            ->map(fn (string $tag) => str_starts_with($tag, '#') ? $tag : '#'.$tag)
            ->implode(' ');
    }

    public function count(): int
    {
        return count($this->tags ?? []);
    }
}
