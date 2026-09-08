<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every Graph API attempt, request and response.
     *
     * request_payload is written REDACTED. Access tokens must never reach this table.
     * It is the one place a token would otherwise leak in plain text, and it is the
     * table most likely to be read by someone debugging at 3am.
     */
    public function up(): void
    {
        Schema::create('publish_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_target_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->string('endpoint', 512);
            $table->json('request_payload')->nullable();
            $table->json('response_body')->nullable();
            $table->unsignedSmallInteger('http_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['post_target_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publish_logs');
    }
};
