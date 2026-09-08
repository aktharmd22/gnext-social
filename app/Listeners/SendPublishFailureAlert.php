<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PublishFailed;
use App\Notifications\PublishFailedNotification;
use App\Services\NotificationDispatcher;

/**
 * Turns a final publish failure into an alert on every configured channel.
 */
class SendPublishFailureAlert
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function handle(PublishFailed $event): void
    {
        $workspace = $event->target->post?->workspace;

        if ($workspace === null) {
            return;
        }

        $notification = new PublishFailedNotification($event->target);

        $this->dispatcher->send(
            $workspace,
            $notification,
            $notification->toPlainText($workspace->timezone)
        );
    }
}
