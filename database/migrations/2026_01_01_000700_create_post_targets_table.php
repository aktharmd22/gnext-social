<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The source of truth for what actually happened.
     *
     * One row per post per destination. A failure on Instagram never blocks the
     * Facebook sibling, and the retry counter lives here rather than on the post, so
     * that retrying one destination cannot re-publish another.
     *
     * The unique constraint is load-bearing: without it, a double submit or a
     * re-dispatched job publishes the same caption to the same page twice, which is
     * the single most embarrassing failure this product could produce.
     */
    public function up(): void
    {
        Schema::create('post_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('queued');
            $table->string('external_id', 128)->nullable();
            $table->text('permalink')->nullable();
            $table->string('container_id', 128)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'social_account_id']);
            $table->index('status');
            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_targets');
    }
};
