<?php

declare(strict_types=1);

namespace App\Enums;

enum PostType: string
{
    case Post = 'post';
    case Reel = 'reel';
    case Story = 'story';
    case Carousel = 'carousel';

    public function label(): string
    {
        return match ($this) {
            self::Post => 'Feed post',
            self::Reel => 'Reel',
            self::Story => 'Story',
            self::Carousel => 'Carousel',
        };
    }

    public function glyph(): string
    {
        return match ($this) {
            self::Post => '▣',
            self::Reel => '▶',
            self::Story => '◰',
            self::Carousel => '▤',
        };
    }

    public function isVideo(): bool
    {
        return $this === self::Reel;
    }

    public function acceptsMultipleMedia(): bool
    {
        return $this === self::Carousel;
    }

    public function minMedia(): int
    {
        return $this === self::Carousel ? 2 : 1;
    }

    public function maxMedia(): int
    {
        return $this === self::Carousel ? 10 : 1;
    }

    /**
     * Target aspect ratio range as [min, max], width divided by height.
     *
     * These are the ratios Meta actually enforces. Validating here, at schedule
     * time, is the difference between a clear message in the composer and a
     * cryptic rejection at 09:00 with nobody awake to fix it.
     *
     * @return array{0: float, 1: float}
     */
    public function aspectRange(): array
    {
        return match ($this) {
            self::Post, self::Carousel => [0.8, 1.91],
            self::Reel, self::Story => [0.5625, 0.5625],
        };
    }
}
