<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Spend events can be archived (hidden from the active list without deleting their history), mirroring
// the shopping/tasks/projects modules. Nullable timestamp = the archive date; null = active.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spending_events', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('spending_events', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
