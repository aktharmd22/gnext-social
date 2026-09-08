<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NotificationsSetting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Where an alert actually goes.
 *
 * Email alone is not enough: a publish that fails at 06:00 needs to reach a
 * phone, not an inbox nobody opens until 09:00. Telegram and a generic webhook
 * exist for exactly that.
 *
 * Every channel is best-effort and independently guarded. A broken webhook must
 * never prevent the email, and neither must ever throw into a queued job that
 * is already handling a failure.
 */
class NotificationDispatcher
{
    /**
     * @param  \Illuminate\Notifications\Notification  $notification
     */
    public function send(Workspace $workspace, $notification, string $plainText): void
    {
        $settings = $this->settingsFor($workspace);

        $this->email($workspace, $settings, $notification);
        $this->telegram($settings, $plainText);
        $this->webhook($settings, $plainText);
    }

    private function settingsFor(Workspace $workspace): ?NotificationsSetting
    {
        return NotificationsSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->first();
    }

    private function email(Workspace $workspace, ?NotificationsSetting $settings, $notification): void
    {
        if ($settings !== null && ! $settings->email_on_failure) {
            return;
        }

        try {
            $recipients = $this->recipients($workspace, $settings);

            if ($recipients->isEmpty()) {
                return;
            }

            Notification::send($recipients, $notification);
        } catch (Throwable $exception) {
            Log::warning('Could not email a publish alert.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Explicit recipients if configured, otherwise every active admin -- the
     * people who can actually reconnect an account or force a retry.
     *
     * @return Collection<int, User>
     */
    private function recipients(Workspace $workspace, ?NotificationsSetting $settings): Collection
    {
        $explicit = collect($settings?->alert_recipients ?? [])->filter();

        if ($explicit->isNotEmpty()) {
            return User::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->whereIn('email', $explicit->all())
                ->where('is_active', true)
                ->get();
        }

        return User::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('role', 'admin')
            ->where('is_active', true)
            ->get();
    }

    private function telegram(?NotificationsSetting $settings, string $message): void
    {
        if ($settings === null || ! $settings->hasTelegram()) {
            return;
        }

        try {
            Http::timeout(10)->withOptions(['http_errors' => false])->post(
                'https://api.telegram.org/bot'.$settings->telegram_bot_token.'/sendMessage',
                [
                    'chat_id' => $settings->telegram_chat_id,
                    'text' => $message,
                    'disable_web_page_preview' => true,
                ]
            );
        } catch (Throwable $exception) {
            Log::warning('Could not send a Telegram alert.', ['error' => $exception->getMessage()]);
        }
    }

    private function webhook(?NotificationsSetting $settings, string $message): void
    {
        if ($settings === null || ! $settings->hasWebhook()) {
            return;
        }

        try {
            Http::timeout(10)->withOptions(['http_errors' => false])->post(
                (string) $settings->webhook_url,
                [
                    'source' => 'gnextsocial',
                    'event' => 'publish.failed',
                    'text' => $message,
                    'sent_at' => now()->toIso8601String(),
                ]
            );
        } catch (Throwable $exception) {
            Log::warning('Could not post to the alert webhook.', ['error' => $exception->getMessage()]);
        }
    }
}
