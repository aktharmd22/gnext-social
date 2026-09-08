<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MediaSourceType;
use App\Enums\MediaStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One image or video attached to a post.
 *
 * source_url is what a human pasted. public_url is what Meta is given. They are
 * almost never the same value, and conflating them is the single most common
 * way this integration fails: a Google Drive share link returns an HTML page,
 * and Meta fetches the bytes itself.
 */
class PostMedia extends Model
{
    use HasFactory;

    protected $table = 'post_media';

    protected $fillable = [
        'post_id',
        'position',
        'source_type',
        'source_url',
        'disk',
        'stored_path',
        'public_url',
        'thumbnail_path',
        'mime',
        'size_bytes',
        'width',
        'height',
        'duration_seconds',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => MediaSourceType::class,
            'status' => MediaStatus::class,
            'position' => 'integer',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_seconds' => 'float',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function isVideo(): bool
    {
        return str_starts_with((string) $this->mime, 'video/');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    /**
     * Width divided by height. Null until the file has been probed.
     */
    public function aspectRatio(): ?float
    {
        if (! $this->width || ! $this->height) {
            return null;
        }

        return round($this->width / $this->height, 4);
    }

    /**
     * A human-readable aspect, for error copy: "1:1", "9:16", "4:5".
     */
    public function aspectLabel(): ?string
    {
        $ratio = $this->aspectRatio();

        if ($ratio === null) {
            return null;
        }

        $known = [
            '1:1' => 1.0,
            '4:5' => 0.8,
            '9:16' => 0.5625,
            '16:9' => 1.7778,
            '1.91:1' => 1.91,
        ];

        foreach ($known as $label => $value) {
            if (abs($ratio - $value) < 0.02) {
                return $label;
            }
        }

        return $ratio > 1
            ? round($ratio, 2).':1'
            : '1:'.round(1 / $ratio, 2);
    }

    public function thumbnailUrl(): ?string
    {
        if ($this->thumbnail_path === null) {
            return null;
        }

        return Storage::disk($this->disk ?? config('gnext.media.disk'))
            ->url($this->thumbnail_path);
    }

    public function humanSize(): ?string
    {
        if (! $this->size_bytes) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = (float) $this->size_bytes;
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, $i === 0 ? 0 : 1).' '.$units[$i];
    }
}
