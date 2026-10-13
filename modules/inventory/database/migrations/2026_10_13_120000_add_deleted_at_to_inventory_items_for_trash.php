<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reversible delete: a deleted stock item (with its history) goes to the 30-day trash instead of vanishing.
// Purged by `trash:purge` (force delete, so the log/alert FK cascades run then). See App\Support\Trash\Trash.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', fn (Blueprint $table) => $table->softDeletes());
    }

    public function down(): void
    {
        Schema::table('inventory_items', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
