<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WeeklyDigestNotification extends Notification
{
    use Queueable;

    /**
     * @param  array{published: int, failed: int, scheduled: int, empty_days: list<string>, top: ?\App\Models\Post}  $summary
     */
    public function __construct(
        public readonly Workspace $workspace,
        public readonly array $summary,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $empty = $this->summary['empty_days'];

        $message = (new MailMessage)
            ->subject('This week on '.$this->workspace->name)
            ->greeting('Your week in content')
            ->line(sprintf(
                '**%d** %s published last week, and **%d** %s scheduled for next week.',
                $this->summary['published'],
                str('post')->plural($this->summary['published']),
                $this->summary['scheduled'],
                str('post')->plural($this->summary['scheduled']) === 'posts' ? 'are' : 'is'
            ));

        if ($this->summary['failed'] > 0) {
            $message->line(sprintf(
                '⚠️ **%d** %s failed to publish and %s still not out.',
                $this->summary['failed'],
                str('post')->plural($this->summary['failed']),
                $this->summary['failed'] === 1 ? 'is' : 'are'
            ));
        }

        if ($this->summary['top'] !== null) {
            $message->line('Best performing: **'
                .($this->summary['top']->title ?: \Illuminate\Support\Str::limit((string) $this->summary['top']->caption, 60))
                .'**');
        }

        // The actionable half. Everything above is history.
        if ($empty === []) {
            $message->line('Every day next week has something scheduled.');
        } else {
            $message->line(sprintf(
                'Nothing scheduled on **%s**.',
                implode(', ', $empty)
            ));
        }

        return $message
            ->action('Open the calendar', url('/calendar'))
            ->salutation('— GnextSocial');
    }

    public function toPlainText(): string
    {
        $empty = $this->summary['empty_days'];

        return implode("\n", array_filter([
            '📅 This week on '.$this->workspace->name,
            $this->summary['published'].' published last week, '.$this->summary['scheduled'].' scheduled for next.',
            $this->summary['failed'] > 0 ? '⚠️ '.$this->summary['failed'].' failed to publish.' : null,
            $empty === []
                ? 'Every day next week is covered.'
                : 'Empty next week: '.implode(', ', $empty),
            '',
            url('/calendar'),
        ]));
    }
}
