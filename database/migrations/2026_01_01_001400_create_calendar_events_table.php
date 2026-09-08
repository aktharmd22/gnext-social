<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The UAE calendar overlay: Ramadan, both Eids, National Day,
     * back-to-school and the peak-summer weeks.
     *
     * Seeded but admin-editable, because the Hijri dates move every year and nobody
     * should need a deploy to correct them.
     */
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('kind', 32)->default('observance');
            $table->boolean('is_approximate')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['workspace_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
