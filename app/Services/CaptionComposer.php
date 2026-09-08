<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Platform;
use App\Models\CaptionTemplate;
use App\Models\Post;

/**
 * Turns a stored post into the exact text that goes to one platform.
 *
 * Two things happen here and nowhere else:
 *
 *   - the per-platform override is applied, so Facebook can run clean prose
 *     while Instagram carries the hashtag block
 *   - the brand footer is appended at publish time rather than pasted into
 *     every caption, so changing a phone number changes it everywhere at once
 */
class CaptionComposer
{
    public function compose(Post $post, Platform $platform): string
    {
        $caption = trim((string) $post->captionFor($platform));

        if (! $post->append_brand_footer) {
            return $caption;
        }

        $footer = $this->footerFor($post);

        if ($footer === null) {
            return $caption;
        }

        // Do not append a footer that is already there: an imported caption
        // often carries one from the spreadsheet it came from.
        if ($caption !== '' && str_contains($caption, $footer)) {
            return $caption;
        }

        return $caption === ''
            ? $footer
            : $caption."\n\n".$footer;
    }

    public function firstComment(Post $post, Platform $platform): ?string
    {
        $comment = trim((string) $post->firstCommentFor($platform));

        return $comment !== '' ? $comment : null;
    }

    private function footerFor(Post $post): ?string
    {
        $footer = CaptionTemplate::withoutGlobalScopes()
            ->where('workspace_id', $post->workspace_id)
            ->where('is_footer', true)
            ->value('body');

        $footer = trim((string) $footer);

        return $footer !== '' ? $footer : null;
    }
}
