<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The (up to four) modules a person pinned to their dashboard and sidebar, in order: picked in the onboarding's
        // "Pin your top 4", editable later. Null = never chose (every existing account), which keeps today's
        // lists as they are.
        Schema::table('users', function (Blueprint $table) {
            $table->json('pinned_modules')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pinned_modules');
        });
    }
};
