<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Destinations we publish to.
 *
 * Kept as an enum specifically so TikTok, LinkedIn and X remain possible without
 * a schema change -- they are explicitly out of scope today, and none of them
 * should be built until asked for.
 */
enum Platform: string
{
    case Facebook = 'facebook';
    case Instagram = 'instagram';

    public function label(): string
    {
        return match ($this) {
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Facebook => 'FB',
            self::Instagram => 'IG',
        };
    }

    public function token(): string
    {
        return $this->value;
    }

    /**
     * Facebook accepts `scheduled_publish_time` and will publish on its own.
     * Instagram has no native scheduling at all: every IG post is a three-step
     * operation performed at the moment of publication, so our worker owns the
     * clock. This single boolean is why the scheduler exists.
     */
    public function schedulesNatively(): bool
    {
        return $this === self::Facebook;
    }

    /**
     * Instagram enforces 50 published posts per rolling 24 hours per account.
     * Facebook has no comparable published-post cap for Pages.
     */
    public function dailyPublishLimit(): ?int
    {
        return match ($this) {
            self::Instagram => 50,
            self::Facebook => null,
        };
    }

    public function captionLimit(): int
    {
        return match ($this) {
            self::Instagram => 2200,
            self::Facebook => 63206,
        };
    }

    public function hashtagLimit(): ?int
    {
        return match ($this) {
            self::Instagram => 30,
            self::Facebook => null,
        };
    }

    public function mentionLimit(): ?int
    {
        return match ($this) {
            self::Instagram => 20,
            self::Facebook => null,
        };
    }

    /**
     * @return array<int, PostType>
     */
    public function supportedTypes(): array
    {
        return match ($this) {
            self::Facebook => [PostType::Post, PostType::Reel, PostType::Story, PostType::Carousel],
            self::Instagram => [PostType::Post, PostType::Reel, PostType::Story, PostType::Carousel],
        };
    }
}
