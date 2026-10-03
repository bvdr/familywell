<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reversible delete: a nullable deleted_at so deletes go to a 30-day trash (SoftDeletes) instead of
// vanishing. Purged by `trash:purge`. See App\Support\Trash\Trash.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_lists', fn (Blueprint $table) => $table->softDeletes());
        Schema::table('shopping_items', fn (Blueprint $table) => $table->softDeletes());
    }

    public function down(): void
    {
        Schema::table('shopping_lists', fn (Blueprint $table) => $table->dropSoftDeletes());
        Schema::table('shopping_items', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
