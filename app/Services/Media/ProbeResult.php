<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * What a file actually is, as opposed to what its extension claims.
 */
final class ProbeResult
{
    public function __construct(
        public readonly string $mime,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?float $duration = null,
        public readonly ?string $videoCodec = null,
        public readonly ?string $audioCodec = null,
        public readonly ?int $sizeBytes = null,
    ) {}

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mime, 'video/');
    }

    /**
     * Width divided by height.
     */
    public function aspectRatio(): ?float
    {
        if (! $this->width || ! $this->height) {
            return null;
        }

        return round($this->width / $this->height, 4);
    }

    /**
     * A label a human recognises: "1:1", "9:16", "4:5".
     */
    public function aspectLabel(): ?string
    {
        $ratio = $this->aspectRatio();

        if ($ratio === null) {
            return null;
        }

        $known = ['1:1' => 1.0, '4:5' => 0.8, '9:16' => 0.5625, '16:9' => 1.7778, '1.91:1' => 1.91];

        foreach ($known as $label => $value) {
            if (abs($ratio - $value) < 0.02) {
                return $label;
            }
        }

        return $ratio > 1
            ? round($ratio, 2).':1'
            : '1:'.round(1 / $ratio, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mime' => $this->mime,
            'width' => $this->width,
            'height' => $this->height,
            'duration_seconds' => $this->duration,
            'video_codec' => $this->videoCodec,
            'audio_codec' => $this->audioCodec,
            'size_bytes' => $this->sizeBytes,
        ];
    }
}
