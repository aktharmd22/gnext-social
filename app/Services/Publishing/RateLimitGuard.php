<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\TargetStatus;
use App\Models\PostTarget;
use App\Models\SocialAccount;

/**
 * Instagram allows 50 published posts per rolling 24 hours per account.
 *
 * Counted locally rather than asked of Meta on every publish: the answer is
 * derivable from our own post_targets, and one fewer Graph call per post is one
 * fewer thing to rate limit. Meta remains the final authority -- error 80004
 * is handled as retryable, so if our count is ever wrong the post is held
 * rather than lost.
 *
 * The UI reads this too, so scheduling past the cap is refused in the composer
 * rather than discovered at 09:00.
 */
class RateLimitGuard
{
    public function publishedInLastDay(SocialAccount $account): int
    {
        return PostTarget::query()
            ->where('social_account_id', $account->id)
            ->where('status', TargetStatus::Published->value)
            ->where('published_at', '>=', now()->subDay())
            ->count();
    }

    public function remaining(SocialAccount $account): ?int
    {
        $limit = $account->platform->dailyPublishLimit();

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->publishedInLastDay($account));
    }

    public function allows(SocialAccount $account): bool
    {
        $remaining = $this->remaining($account);

        return $remaining === null || $remaining > 0;
    }

    /**
     * When the oldest post in the window falls out, freeing a slot.
     */
    public function nextSlotAt(SocialAccount $account): ?\DateTimeInterface
    {
        if ($this->allows($account)) {
            return null;
        }

        $oldest = PostTarget::query()
            ->where('social_account_id', $account->id)
            ->where('status', TargetStatus::Published->value)
            ->where('published_at', '>=', now()->subDay())
            ->orderBy('published_at')
            ->value('published_at');

        return $oldest !== null
            ? \Illuminate\Support\Carbon::parse($oldest)->addDay()
            : null;
    }

    public function reason(SocialAccount $account): ?string
    {
        if ($this->allows($account)) {
            return null;
        }

        $next = $this->nextSlotAt($account);

        return sprintf(
            '%s has published %d posts in the last 24 hours, which is Instagram\'s limit. %s',
            $account->displayName(),
            $this->publishedInLastDay($account),
            $next !== null
                ? 'The next slot opens at '.$next->format('H:i \o\n j M').'.'
                : 'It will publish once the window clears.'
        );
    }
}
