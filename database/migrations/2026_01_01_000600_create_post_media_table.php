<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Media, after ingestion.
     *
     * source_url is what a human pasted, often a Google Drive share link, which is an
     * HTML page and not an image. public_url is what Meta is actually given: always a
     * direct URL on a disk we control, because Meta fetches the bytes itself and
     * cannot follow a Drive viewer page.
     *
     * thumbnail_path is an addition to the specified shape. The brief requires
     * thumbnail generation in the ingestion pipeline, and thumbnails rather than
     * originals in grids, but gave the column nowhere to live.
     */
    public function up(): void
    {
        Schema::create('post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('source_type', 16);
            $table->text('source_url')->nullable();
            $table->string('disk', 32)->nullable();
            $table->string('stored_path')->nullable();
            $table->text('public_url')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->float('duration_seconds')->nullable();
            $table->string('status', 16)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['post_id', 'position']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_media');
    }
};
