<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Loose workspace preferences that do not each deserve a column.
     *
     * Import defaults live here: the default publish time, the accounts a new
     * import targets, and the date format the last import used. They are
     * preferences, not data anything joins against, so a JSON blob is honest
     * about what they are.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
