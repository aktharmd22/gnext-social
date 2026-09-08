<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Str;

/**
 * Warns when a caption is close to something already published.
 *
 * Recycling content is deliberate and useful; recycling it by accident, three
 * weeks apart, on the same page, is the kind of thing an audience notices and a
 * scheduler does not. This warns, and never blocks -- the operator knows things
 * we do not.
 */
class DuplicateDetector
{
    /**
     * @return array{post: Post, similarity: int}|null
     */
    public function findSimilar(Post $post): ?array
    {
        $caption = $this->normalise((string) $post->caption);

        // Short captions collide by chance ("New arrivals" is not plagiarism).
        if (Str::length($caption) < 40) {
            return null;
        }

        $threshold = (int) config('gnext.composer.duplicate_similarity', 80);
        $lookback = (int) config('gnext.composer.duplicate_lookback_days', 60);

        $candidates = Post::query()
            ->where('id', '!=', $post->id ?? 0)
            ->whereNotNull('published_at')
            ->where('published_at', '>=', now()->subDays($lookback))
            ->whereNotNull('caption')
            ->latest('published_at')
            ->limit(200)
            ->get(['id', 'title', 'caption', 'published_at', 'workspace_id']);

        $best = null;

        foreach ($candidates as $candidate) {
            $similarity = $this->similarity($caption, $this->normalise((string) $candidate->caption));

            if ($similarity >= $threshold && ($best === null || $similarity > $best['similarity'])) {
                $best = ['post' => $candidate, 'similarity' => $similarity];
            }
        }

        return $best;
    }

    /**
     * Percentage similarity, 0-100.
     */
    public function similarity(string $a, string $b): int
    {
        if ($a === '' || $b === '') {
            return 0;
        }

        if ($a === $b) {
            return 100;
        }

        /*
         * similar_text is O(n^3) in the worst case, so long captions are
         * compared on a bounded prefix. Two captions that share their first
         * 1000 characters are the same post for this purpose.
         */
        $a = Str::limit($a, 1000, '');
        $b = Str::limit($b, 1000, '');

        similar_text($a, $b, $percent);

        return (int) round($percent);
    }

    /**
     * Strip the things that differ without the content differing: case,
     * whitespace, hashtags, mentions, URLs and emoji.
     */
    public function normalise(string $caption): string
    {
        $caption = Str::lower($caption);

        $caption = preg_replace('#https?://\S+#u', ' ', $caption) ?? $caption;
        $caption = preg_replace('/(?<!\w)[#@][\p{L}\p{N}_.]+/u', ' ', $caption) ?? $caption;

        // Emoji and pictographs.
        $caption = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', ' ', $caption) ?? $caption;

        $caption = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $caption) ?? $caption;
        $caption = preg_replace('/\s+/u', ' ', $caption) ?? $caption;

        return trim($caption);
    }
}
