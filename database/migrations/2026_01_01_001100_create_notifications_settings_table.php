<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a failure at 06:00 gets sent.
     *
     * telegram_bot_token is an addition to the specified shape. A chat id alone
     * cannot receive a message, so storing one without a bot token would have made
     * the Telegram channel non-functional. Encrypted, like every other secret.
     */
    public function up(): void
    {
        Schema::create('notifications_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->boolean('email_on_failure')->default(true);
            $table->boolean('email_on_publish')->default(false);
            $table->text('webhook_url')->nullable();
            $table->string('telegram_chat_id', 64)->nullable();
            $table->text('telegram_bot_token')->nullable();
            $table->json('alert_recipients')->nullable();
            $table->timestamps();

            $table->unique('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_settings');
    }
};
