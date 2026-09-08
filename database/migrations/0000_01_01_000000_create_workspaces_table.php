<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workspaces exist even though there is exactly one today.
     *
     * Adding this column now costs a column. Adding it in six months, once posts,
     * media, accounts and logs have all accumulated, costs a rewrite. Every model
     * scopes through it via a global scope from day one.
     */
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 96)->unique();
            $table->string('timezone', 64)->default('Asia/Dubai');
            $table->string('logo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
