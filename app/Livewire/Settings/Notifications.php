<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\NotificationsSetting;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

/**
 * Settings > Notifications. Where a failure at 06:00 actually reaches someone.
 */
class Notifications extends Component
{
    public bool $emailOnFailure = true;

    public bool $emailOnPublish = false;

    public string $recipients = '';

    public string $webhookUrl = '';

    public string $telegramChatId = '';

    public string $telegramBotToken = '';

    public bool $hasStoredToken = false;

    public bool $replacingToken = false;

    public ?array $testResult = null;

    public function mount(): void
    {
        Gate::authorize('manage-notifications');

        $settings = $this->settings();

        $this->emailOnFailure = (bool) ($settings?->email_on_failure ?? true);
        $this->emailOnPublish = (bool) ($settings?->email_on_publish ?? false);
        $this->recipients = implode(', ', (array) ($settings?->alert_recipients ?? []));
        $this->webhookUrl = (string) ($settings?->webhook_url ?? '');
        $this->telegramChatId = (string) ($settings?->telegram_chat_id ?? '');
        $this->hasStoredToken = filled($settings?->telegram_bot_token);
        $this->replacingToken = ! $this->hasStoredToken;
    }

    public function save(ActivityLogger $log): void
    {
        Gate::authorize('manage-notifications');

        $this->validate([
            'webhookUrl' => ['nullable', 'url', 'max:2000'],
            'telegramChatId' => ['nullable', 'string', 'max:64'],
            'telegramBotToken' => ['nullable', 'string', 'max:255'],
            'recipients' => ['nullable', 'string', 'max:2000'],
        ]);

        $emails = collect(preg_split('/[\s,;]+/', $this->recipients) ?: [])
            ->filter()
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();

        $settings = $this->settings() ?? new NotificationsSetting([
            'workspace_id' => auth()->user()->workspace_id,
        ]);

        $settings->fill([
            'workspace_id' => auth()->user()->workspace_id,
            'email_on_failure' => $this->emailOnFailure,
            'email_on_publish' => $this->emailOnPublish,
            'alert_recipients' => $emails,
            'webhook_url' => $this->webhookUrl !== '' ? $this->webhookUrl : null,
            'telegram_chat_id' => $this->telegramChatId !== '' ? $this->telegramChatId : null,
        ]);

        if ($this->telegramBotToken !== '') {
            $settings->telegram_bot_token = $this->telegramBotToken;
        }

        $settings->save();

        $log->logChanges('notifications.updated', $settings);

        $this->telegramBotToken = '';
        $this->hasStoredToken = filled($settings->telegram_bot_token);
        $this->replacingToken = ! $this->hasStoredToken;

        $this->dispatch('toast', message: 'Notification settings saved.');
    }

    /**
     * Send a real message on every configured channel.
     *
     * A channel that is configured but broken is worse than one that is off,
     * because it looks like coverage.
     */
    public function sendTest(): void
    {
        Gate::authorize('manage-notifications');

        $settings = $this->settings();

        if ($settings === null) {
            $this->testResult = ['ok' => false, 'detail' => 'Save your settings first.'];

            return;
        }

        $results = [];

        if ($settings->hasTelegram()) {
            try {
                $response = Http::timeout(10)->withOptions(['http_errors' => false])->post(
                    'https://api.telegram.org/bot'.$settings->telegram_bot_token.'/sendMessage',
                    ['chat_id' => $settings->telegram_chat_id, 'text' => 'GnextSocial test alert. Publishing failures will look like this.']
                );

                $results[] = $response->successful()
                    ? 'Telegram: delivered.'
                    : 'Telegram: refused ('.($response->json('description') ?? 'HTTP '.$response->status()).').';
            } catch (\Throwable $e) {
                $results[] = 'Telegram: could not be reached.';
            }
        }

        if ($settings->hasWebhook()) {
            try {
                $response = Http::timeout(10)->withOptions(['http_errors' => false])
                    ->post((string) $settings->webhook_url, [
                        'source' => 'gnextsocial',
                        'event' => 'test',
                        'text' => 'GnextSocial test alert.',
                    ]);

                $results[] = $response->successful()
                    ? 'Webhook: accepted.'
                    : 'Webhook: answered HTTP '.$response->status().'.';
            } catch (\Throwable $e) {
                $results[] = 'Webhook: could not be reached.';
            }
        }

        $this->testResult = $results === []
            ? ['ok' => false, 'detail' => 'No Telegram or webhook is configured, so there was nothing to test. Email is always on for failures.']
            : ['ok' => true, 'detail' => implode(' ', $results)];
    }

    public function startReplacingToken(): void
    {
        $this->replacingToken = true;
        $this->telegramBotToken = '';
    }

    private function settings(): ?NotificationsSetting
    {
        return NotificationsSetting::query()
            ->where('workspace_id', auth()->user()->workspace_id)
            ->first();
    }

    public function render()
    {
        return view('livewire.settings.notifications');
    }
}
