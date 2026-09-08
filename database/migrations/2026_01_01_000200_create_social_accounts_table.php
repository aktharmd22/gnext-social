<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per publishable destination.
     *
     * A Facebook Page and the Instagram account linked to it are two rows, not one,
     * because they hold different tokens, fail independently, and are selected
     * independently in the composer.
     *
     * page_id and ig_user_id are short strings on purpose: Meta ids are numeric and
     * under 20 characters, and keeping them narrow keeps the identity unique index
     * comfortably inside the 3072-byte index prefix limit on MariaDB.
     */
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16);
            $table->string('page_id', 64)->nullable();
            $table->string('ig_user_id', 64)->nullable();
            $table->string('name');
            $table->string('username', 128)->nullable();
            $table->string('avatar_url', 1024)->nullable();

            // Nullable because a disconnected account legitimately has no
            // token. Disconnecting deactivates and forgets the credential; it
            // never deletes the row, because post_targets cascade from here and
            // deleting would take published permalinks, insights and logs with
            // it.
            $table->text('access_token')->nullable();
            $table->string('token_type', 32)->default('page');
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'platform', 'is_active']);
            $table->index('token_expires_at');
            $table->unique(
                ['workspace_id', 'platform', 'page_id', 'ig_user_id'],
                'social_accounts_identity_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
