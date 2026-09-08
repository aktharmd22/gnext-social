<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Two roles only. No permissions package: the capability matrix is
            // small enough that policies express it more clearly than a table
            // of role/permission rows nobody will ever read.
            $table->string('role', 16)->default('user');

            // Timestamps are stored UTC and rendered in this zone. Falls back
            // to the workspace zone when null.
            $table->string('timezone', 64)->nullable();
            $table->string('avatar_path')->nullable();
            $table->timestamp('last_login_at')->nullable();

            // Deactivate rather than delete: a departed user still authored
            // posts, approved content and appears throughout the activity log.
            $table->boolean('is_active')->default(true);

            // Fortify TOTP. Mandatory for admins, who hold Page access tokens.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->index(['workspace_id', 'is_active']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
