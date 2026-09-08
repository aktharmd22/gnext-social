<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PostTarget;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A destination has exhausted its attempts, or hit something no retry can fix.
 *
 * Raised once per target, at the point the outcome becomes final, so that a
 * failure at 06:00 reaches a human rather than sitting in a log.
 */
class PublishFailed
{
    use Dispatchable;

    public function __construct(public readonly PostTarget $target) {}
}
