<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PostTarget;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your post did not go out", with the cause in plain language and a direct
 * link to retry it.
 *
 * Never "An error occurred". The whole point of GraphError is that by the time
 * a message reaches here it already says which account, what happened, and what
 * to do about it.
 */
class PublishFailedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly PostTarget $target) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $post = $this->target->post;
        $account = $this->target->socialAccount;
        $timezone = $notifiable->displayTimezone();

        $when = $post?->scheduled_at?->copy()->setTimezone($timezone);
        $title = $post?->title ?: \Illuminate\Support\Str::limit((string) $post?->caption, 60);

        return (new MailMessage)
            ->error()
            ->subject('Post did not publish: '.$title)
            ->greeting('A post did not go out.')
            ->line('**'.$title.'**')
            ->line(sprintf(
                'Destination: %s. Scheduled for %s.',
                $account?->displayName() ?? 'an account that no longer exists',
                $when !== null ? $when->format('D j M Y, H:i').' '.\App\Support\Zone::label($timezone) : 'an unknown time'
            ))
            ->line('---')
            ->line($this->target->error_message ?: 'No reason was recorded.')
            ->action('Open the post', url('/posts?highlight='.$post?->id))
            ->line(sprintf(
                'This was attempt %d of %d.',
                $this->target->attempts,
                (int) config('gnext.publishing.max_attempts')
            ))
            ->salutation('— GnextSocial');
    }

    /**
     * The same message, flattened for Telegram and webhooks, which have no
     * concept of a mail layout.
     */
    public function toPlainText(string $timezone = 'Asia/Dubai'): string
    {
        $post = $this->target->post;
        $account = $this->target->socialAccount;
        $when = $post?->scheduled_at?->copy()->setTimezone($timezone);
        $title = $post?->title ?: \Illuminate\Support\Str::limit((string) $post?->caption, 60);

        return implode("\n", array_filter([
            '⚠️ Post did not publish',
            $title,
            'Where: '.($account?->displayName() ?? 'unknown account'),
            $when !== null ? 'Due: '.$when->format('D j M, H:i').' '.\App\Support\Zone::label($timezone) : null,
            '',
            (string) $this->target->error_message,
        ]));
    }
}
