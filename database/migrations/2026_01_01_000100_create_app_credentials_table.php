<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meta app configuration, owned by the admin at runtime.
     *
     * Deliberately not in .env: the brief requires an admin to be able to point this
     * installation at a different Meta app without a deploy. The secret and the
     * webhook verify token are encrypted at rest and are never rendered, returned or
     * logged. The UI shows a masked value and a "Replace secret" action only.
     */
    public function up(): void
    {
        Schema::create('app_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('meta_app_id', 64);
            $table->text('meta_app_secret');
            $table->string('graph_version', 16)->default('v21.0');
            $table->string('redirect_uri', 512);
            $table->text('webhook_verify_token')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One active credential set per workspace.
            $table->unique('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_credentials');
    }
};
