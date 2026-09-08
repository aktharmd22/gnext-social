<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SocialAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "This token is about to die, and publishing stops when it does."
 *
 * Names the account and the date, because "Invalid token" tells nobody which of
 * four connected pages to go and reconnect.
 */
class TokenExpiringNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly SocialAccount $account) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expired = $this->account->tokenHasExpired();
        $days = $this->account->tokenExpiresInDays();

        $message = (new MailMessage)
            ->subject($expired
                ? 'Publishing has stopped for '.$this->account->displayName()
                : 'Reconnect '.$this->account->displayName().' soon');

        if ($expired) {
            $message->error()
                ->greeting('Publishing has stopped.')
                ->line(sprintf(
                    'The access token for **%s** expired on %s. Nothing will publish to it until it is reconnected.',
                    $this->account->displayName(),
                    $this->account->token_expires_at?->format('j M Y') ?? 'an unknown date'
                ));
        } else {
            $message->greeting('A connection needs renewing.')
                ->line(sprintf(
                    'The access token for **%s** expires in %d %s, on %s. Publishing will stop when it does.',
                    $this->account->displayName(),
                    $days,
                    str('day')->plural((int) $days),
                    $this->account->token_expires_at?->format('j M Y') ?? 'an unknown date'
                ));
        }

        return $message
            ->action('Reconnect it', url('/settings/accounts'))
            ->line('Reconnecting takes about a minute and does not affect anything already published.')
            ->salutation('— GnextSocial');
    }

    public function toPlainText(): string
    {
        $expired = $this->account->tokenHasExpired();
        $days = $this->account->tokenExpiresInDays();

        return implode("\n", [
            $expired ? '⛔ Publishing has stopped' : '⚠️ Reconnect needed soon',
            $this->account->displayName(),
            $expired
                ? 'The token expired on '.($this->account->token_expires_at?->format('j M Y') ?? 'an unknown date').'.'
                : 'The token expires in '.$days.' '.str('day')->plural((int) $days).'.',
            '',
            'Reconnect at '.url('/settings/accounts'),
        ]);
    }
}
