<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Metrics captured at fixed windows after publication.
     *
     * Keyed to the target, not the post: the same caption performs differently on a
     * Page than on an Instagram account, and averaging the two hides exactly the
     * signal the heatmap is meant to surface.
     */
    public function up(): void
    {
        Schema::create('post_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_target_id')->constrained()->cascadeOnDelete();
            $table->timestamp('captured_at');
            $table->string('window', 8);
            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedBigInteger('comments')->nullable();
            $table->unsignedBigInteger('shares')->nullable();
            $table->unsignedBigInteger('saves')->nullable();
            $table->unsignedBigInteger('video_views')->nullable();
            $table->decimal('engagement_rate', 8, 4)->nullable();
            $table->timestamps();

            $table->unique(['post_target_id', 'window']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_insights');
    }
};
