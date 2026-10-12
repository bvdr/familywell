<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Transfers (settle-up payments) become deletable from the Transfers tab, reversibly like expenses:
// soft delete into the 30-day trash (SpendingTrash lists + restores them; trash:purge removes them).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spending_settlements', fn (Blueprint $table) => $table->softDeletes());
    }

    public function down(): void
    {
        Schema::table('spending_settlements', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
