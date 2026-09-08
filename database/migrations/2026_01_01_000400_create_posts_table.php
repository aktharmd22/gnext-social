<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One piece of content, before it is fanned out to destinations.
     *
     * `status` here is DERIVED from post_targets, never authoritative. When Facebook
     * succeeds and Instagram fails, this column reads `partially_published`. A single
     * status column cannot express a split outcome, so it does not try to.
     *
     * `public_uuid` is an addition to the specified shape. Client review links are
     * signed, but handing an outside reviewer /review/47 leaks how much you publish.
     * A uuid does not.
     *
     * scheduled_at is stored UTC, always. Display conversion is a view concern.
     */
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('caption')->nullable();
            $table->text('caption_ar')->nullable();
            $table->string('type', 16)->default('post');
            $table->text('first_comment')->nullable();
            $table->boolean('append_brand_footer')->default(true);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('status', 32)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_note')->nullable();
            $table->string('source', 16)->default('manual');
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // The calendar month query leans on this pair.
            $table->index(['workspace_id', 'scheduled_at']);
            $table->index(['workspace_id', 'status']);
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
