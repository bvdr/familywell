<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A spend can now be paid by a family User (payer_id) OR a guest from the core guest-sharing system
// (payer_guest_id -> guests.id). Guests + guest links live in the app-core `guests`/`guest_links`
// tables (create_guest_tables), so this module only wires the payer FK.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spending_spends', function (Blueprint $table) {
            // Exactly one of payer_id / payer_guest_id is set, so payer_id is now nullable.
            $table->foreignId('payer_id')->nullable()->comment('the member who paid')->change();
            // Removing a guest removes the spends they added, so the split stays consistent (no
            // orphaned payer -> phantom balance entry).
            $table->foreignId('payer_guest_id')->nullable()->after('payer_id')
                ->constrained('guests')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('spending_spends', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payer_guest_id');
        });
    }
};
