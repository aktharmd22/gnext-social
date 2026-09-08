<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Where alerts go. A failure at 06:00 should reach a phone, not an inbox
 * nobody opens until 09:00 -- hence the Telegram and webhook channels.
 */
class NotificationsSetting extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'email_on_failure',
        'email_on_publish',
        'webhook_url',
        'telegram_chat_id',
        'telegram_bot_token',
        'alert_recipients',
    ];

    /** @var list<string> */
    protected $hidden = [
        'telegram_bot_token',
    ];

    protected function casts(): array
    {
        return [
            'email_on_failure' => 'boolean',
            'email_on_publish' => 'boolean',
            'telegram_bot_token' => 'encrypted',
            'alert_recipients' => 'array',
        ];
    }

    public function hasTelegram(): bool
    {
        return filled($this->telegram_chat_id) && filled($this->telegram_bot_token);
    }

    public function hasWebhook(): bool
    {
        return filled($this->webhook_url);
    }
}
