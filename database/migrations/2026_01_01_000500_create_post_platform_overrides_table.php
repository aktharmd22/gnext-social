<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional per-platform caption.
     *
     * Exists so Facebook can run clean prose while Instagram carries the hashtag
     * block, without duplicating the post.
     */
    public function up(): void
    {
        Schema::create('post_platform_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16);
            $table->text('caption')->nullable();
            $table->text('first_comment')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_platform_overrides');
    }
};
