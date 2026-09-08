<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InsightWindow;
use App\Enums\Platform;
use App\Enums\TargetStatus;
use App\Models\PostInsight;
use App\Models\PostTarget;
use App\Services\Meta\GraphException;
use App\Services\Meta\Publishers\PublisherFactory;
use Illuminate\Support\Collection;

/**
 * Pulls performance metrics for something already published.
 *
 * Captured at fixed windows rather than continuously: a post's 24-hour number
 * is comparable to another post's 24-hour number, whereas "whatever it was when
 * we last looked" is comparable to nothing.
 *
 * Keyed to the target, never the post: the same caption performs differently on
 * a Page than on an Instagram account, and averaging the two hides exactly the
 * signal the heatmap exists to surface.
 */
class InsightsCollector
{
    public function __construct(private readonly PublisherFactory $publishers = new PublisherFactory) {}

    /**
     * Targets whose window has arrived and which have not been captured yet.
     *
     * @return Collection<int, PostTarget>
     */
    public function due(InsightWindow $window, int $limit = 100): Collection
    {
        return PostTarget::query()
            ->with(['socialAccount', 'post'])
            ->where('status', TargetStatus::Published->value)
            ->whereNotNull('external_id')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now()->subHours($window->hoursAfterPublish()))
            ->whereDoesntHave('insights', fn ($q) => $q->where('window', $window->value))
            ->limit($limit)
            ->get();
    }

    /**
     * Capture one window for one target. Returns null when Meta refused, which
     * is common and not worth failing a scheduled command over.
     */
    public function capture(PostTarget $target, InsightWindow $window): ?PostInsight
    {
        $account = $target->socialAccount;

        if ($account === null || ! $account->isPublishable() || $target->external_id === null) {
            return null;
        }

        try {
            $metrics = $account->platform === Platform::Instagram
                ? $this->instagram($target)
                : $this->facebook($target);
        } catch (GraphException) {
            // Insights are frequently unavailable: too new, too few viewers, or
            // a permission the app was never granted. None of that is an error
            // worth surfacing.
            return null;
        }

        if ($metrics === []) {
            return null;
        }

        $metrics['engagement_rate'] = $this->engagementRate($metrics);

        return PostInsight::updateOrCreate(
            ['post_target_id' => $target->id, 'window' => $window->value],
            array_merge($metrics, ['captured_at' => now()]),
        );
    }

    /**
     * @return array<string, int|null>
     */
    private function instagram(PostTarget $target): array
    {
        $client = $this->publishers->clientFor($target->socialAccount);
        $token = $target->socialAccount->access_token;

        $response = $client->get($target->external_id.'/insights', [
            'metric' => 'impressions,reach,likes,comments,saved,shares',
        ], $token);

        $values = [];

        foreach ($response['data'] ?? [] as $metric) {
            $values[$metric['name'] ?? ''] = (int) ($metric['values'][0]['value'] ?? 0);
        }

        return array_filter([
            'impressions' => $values['impressions'] ?? null,
            'reach' => $values['reach'] ?? null,
            'likes' => $values['likes'] ?? null,
            'comments' => $values['comments'] ?? null,
            'saves' => $values['saved'] ?? null,
            'shares' => $values['shares'] ?? null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array<string, int|null>
     */
    private function facebook(PostTarget $target): array
    {
        $client = $this->publishers->clientFor($target->socialAccount);
        $token = $target->socialAccount->access_token;

        // Reactions, comments and shares come from the post itself; reach and
        // impressions come from the insights edge. Two calls, because Meta
        // splits them.
        $summary = $client->get($target->external_id, [
            'fields' => 'shares,likes.summary(true),comments.summary(true)',
        ], $token);

        $insights = $client->get($target->external_id.'/insights', [
            'metric' => 'post_impressions,post_impressions_unique,post_video_views',
        ], $token);

        $values = [];

        foreach ($insights['data'] ?? [] as $metric) {
            $values[$metric['name'] ?? ''] = (int) ($metric['values'][0]['value'] ?? 0);
        }

        return array_filter([
            'impressions' => $values['post_impressions'] ?? null,
            'reach' => $values['post_impressions_unique'] ?? null,
            'video_views' => $values['post_video_views'] ?? null,
            'likes' => isset($summary['likes']['summary']['total_count'])
                ? (int) $summary['likes']['summary']['total_count'] : null,
            'comments' => isset($summary['comments']['summary']['total_count'])
                ? (int) $summary['comments']['summary']['total_count'] : null,
            'shares' => isset($summary['shares']['count']) ? (int) $summary['shares']['count'] : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Engagements over reach, as a percentage.
     *
     * Reach rather than impressions on purpose: the same person seeing a post
     * three times is one opportunity to engage, not three.
     *
     * @param  array<string, int|null>  $metrics
     */
    private function engagementRate(array $metrics): ?float
    {
        $reach = (int) ($metrics['reach'] ?? 0);

        if ($reach <= 0) {
            return null;
        }

        $engagements = (int) ($metrics['likes'] ?? 0)
            + (int) ($metrics['comments'] ?? 0)
            + (int) ($metrics['shares'] ?? 0)
            + (int) ($metrics['saves'] ?? 0);

        return round(($engagements / $reach) * 100, 4);
    }
}
