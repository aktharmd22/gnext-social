<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvitedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $inviterName) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You have been added to '.config('app.name'))
            ->greeting('Welcome.')
            ->line($this->inviterName.' has added you to '.config('app.name').'.')
            ->action('Set your password', url('/forgot-password'))
            ->line('Use the address this email was sent to.')
            ->salutation('— GnextSocial');
    }
}
