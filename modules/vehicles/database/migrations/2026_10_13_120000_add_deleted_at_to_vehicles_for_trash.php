<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reversible delete: a deleted car, and each record a person can delete on its own (mileage reading, fuel
// fill, repair, document, maintenance schedule), go to the 30-day trash instead of vanishing. Purged by
// `trash:purge` (force delete, so the FK cascades and the storage-file cleanup run then). See
// App\Support\Trash\Trash and VehiclesTrash.
return new class extends Migration
{
    private const TABLES = ['vehicles_vehicles', 'vehicles_mileage_log', 'vehicles_fuel_entries', 'vehicles_repairs', 'vehicles_documents', 'vehicles_maintenance_schedules'];

    public function up(): void
    {
        foreach (self::TABLES as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->softDeletes());
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropSoftDeletes());
        }
    }
};
