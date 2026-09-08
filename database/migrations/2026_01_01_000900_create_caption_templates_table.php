<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reusable caption bodies, and the brand footer.
     *
     * The footer is a template appended at publish time rather than text pasted into
     * every caption, so that changing a phone number changes it everywhere at once.
     */
    public function up(): void
    {
        Schema::create('caption_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('body');
            $table->boolean('is_footer')->default(false);
            $table->string('language', 8)->default('en');
            $table->timestamps();

            $table->index(['workspace_id', 'is_footer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caption_templates');
    }
};
