<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reversible delete: deleted contacts (with their contact methods and the rows other modules keep on them) go to the 30-day trash instead of vanishing. Purged by `trash:purge`
// (force delete, so the DB cascades run then). See App\Support\Trash\Trash.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts_people', fn (Blueprint $table) => $table->softDeletes());
    }

    public function down(): void
    {
        Schema::table('contacts_people', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
