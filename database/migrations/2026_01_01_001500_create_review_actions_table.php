<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Actions taken through a shareable client review link.
     *
     * An addition to the specified shape. The brief requires every action on a review
     * link to be logged against a pseudonymous reviewer identity, but gave it nowhere
     * to live: activity_logs is keyed to a user_id, and a review link deliberately
     * has no account behind it.
     *
     * reviewer_hash is a salted digest of the link recipient, so that repeat visits
     * are correlatable without storing anything identifying.
     */
    public function up(): void
    {
        Schema::create('review_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('reviewer_hash', 64);
            $table->string('action', 16);
            $table->text('note')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['post_id', 'created_at']);
            $table->index('reviewer_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_actions');
    }
};
