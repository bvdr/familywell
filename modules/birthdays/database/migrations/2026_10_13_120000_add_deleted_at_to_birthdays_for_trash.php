<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reversible delete: removed birthdays go to the 30-day trash instead of vanishing. Purged by `trash:purge`
// (force delete, so the DB cascades run then). See App\Support\Trash\Trash.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('birthdays_dates', fn (Blueprint $table) => $table->softDeletes());
    }

    public function down(): void
    {
        Schema::table('birthdays_dates', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
