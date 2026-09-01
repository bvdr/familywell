<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 5: the family's Vehicles preferences + the mileage-milestone idempotency marker.
//
//  1) vehicles_settings — one row per family (family_id unique), created on demand. Mirrors
//     Modules\Inventory\InventorySetting exactly so the two modules share one "module settings" mental
//     model. It holds the DISPLAY UNIT SYSTEM (metric km vs imperial miles — vehicles has no competing
//     unit framework, this single switch is it), the notification condition toggles (maintenance /
//     documents / mileage-milestone), and the family's CUSTOM default maintenance-template intervals
//     (a JSON map of templateKey => {km, months} that overrides MaintenanceTemplate's shipped defaults
//     when pre-filling the add-schedule form).
//
//  2) vehicles_vehicles.milestone_notified_km — the largest mileage milestone (multiple of
//     MILESTONE_KM) we've already pushed a notification for on this car. The milestone notification is
//     optional (off by default) and idempotent per 10k-km period via this column: we only notify when
//     the car crosses a NEW milestone beyond the one recorded here.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->unique()->constrained()->cascadeOnDelete();

            // Display unit system. Distances are always STORED in km; this only changes how they're shown
            // (km vs miles). 'metric' default keeps every existing family unchanged.
            $table->string('unit_system')->default('metric');

            // Which conditions may raise a notification for the family (composes with the per-user push
            // mute, exactly like InventorySetting). Maintenance + documents on by default (they were the
            // P3/P4 behaviour); the mileage milestone is opt-in.
            $table->boolean('notify_maintenance')->default(true);
            $table->boolean('notify_documents')->default(true);
            $table->boolean('notify_mileage_milestone')->default(false);

            // Family overrides for the shipped maintenance-template default intervals. {key: {km, months}};
            // an absent key falls back to MaintenanceTemplate::TEMPLATES. Nullable = "no overrides yet".
            $table->json('template_intervals')->nullable();

            $table->timestamps();
        });

        Schema::table('vehicles_vehicles', function (Blueprint $table) {
            // The last mileage milestone we notified about (idempotency marker for the optional milestone
            // notification). 0 = none yet.
            $table->unsignedInteger('milestone_notified_km')->default(0)->after('current_mileage');
        });

        Schema::table('vehicles_expense_links', function (Blueprint $table) {
            // Which repair produced this expense, when it came from a repair's opt-in cost mirror (null for
            // a maintenance completion or an ad-hoc "Add expense"). Lets the Vehicle Timeline show a repair's
            // cost ONCE — as the repair entry — instead of double-counting it as a separate expense entry.
            $table->foreignId('repair_id')->nullable()->after('maintenance_history_id')
                ->constrained('vehicles_repairs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles_expense_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('repair_id');
        });
        Schema::table('vehicles_vehicles', function (Blueprint $table) {
            $table->dropColumn('milestone_notified_km');
        });
        Schema::dropIfExists('vehicles_settings');
    }
};
