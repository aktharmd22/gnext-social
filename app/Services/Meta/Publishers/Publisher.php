<?php

declare(strict_types=1);

namespace App\Services\Meta\Publishers;

use App\Models\PostTarget;

interface Publisher
{
    /**
     * Put one post live at one destination.
     *
     * @throws \App\Services\Meta\GraphException
     */
    public function publish(PostTarget $target, string $caption): PublishResult;

    /**
     * Add the first comment beneath a published post. Failure here must never
     * fail the post itself: the content is already live.
     */
    public function postFirstComment(PostTarget $target, string $comment): ?string;
}
