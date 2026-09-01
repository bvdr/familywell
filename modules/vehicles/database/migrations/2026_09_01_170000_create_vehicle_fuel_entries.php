<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// V1.1 Phase 1: FUEL — a lightweight fill-up log (deliberately NOT a full fuel-management system). Each
// entry records one fill: how many litres, at what cost (optional), at what odometer, and whether the tank
// was filled to full. `is_full_tank` is what makes consumption computable: L/100km is measured between two
// consecutive FULL-tank fills (distance from the mileage delta, litres = what was burned between them).
//
// A fuel entry reuses the module's mileage flow — recording a fill at a higher odometer moves the car's
// current_mileage forward and writes ONE mileage_log row (the same guard MaintenanceCompletion uses), so a
// fill never double-counts the odometer. A costed fill mirrors into Spending via the P3 VehicleExpenseLink
// bridge (tagged with fuel_entry_id below), exactly like a maintenance completion or a repair.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles_fuel_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles_vehicles')->cascadeOnDelete();

            $table->date('filled_on');                            // when the fill happened
            $table->decimal('liters', 8, 2);                      // litres pumped (stored metric; displayed per unit_system)
            $table->decimal('cost', 12, 2)->nullable();           // total paid, mirrored into Spending when set
            $table->unsignedInteger('mileage');                   // odometer at the fill (drives consumption)
            // Whether the tank was filled to full. Consumption is only computable across FULL-to-FULL fills,
            // so this defaults to true (the common case) and partial fills still count their litres toward
            // the next full-tank segment.
            $table->boolean('is_full_tank')->default(true);
            $table->string('station')->nullable();                // where (free text, optional)
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Consumption reads fills ordered by odometer within a vehicle — index the pair.
            $table->index(['vehicle_id', 'mileage']);
            $table->index(['vehicle_id', 'filled_on']);
        });

        Schema::table('vehicles_expense_links', function (Blueprint $table) {
            // Which fuel entry produced this expense, when it came from a costed fill (null for a maintenance
            // completion, a repair, or an ad-hoc "Add expense"). Lets the Vehicle Timeline surface a fuel
            // cost ONCE — as the fill — instead of double-counting it as a separate generic expense entry,
            // mirroring the repair_id dedup added in P5.
            $table->foreignId('fuel_entry_id')->nullable()->after('repair_id')
                ->constrained('vehicles_fuel_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles_expense_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fuel_entry_id');
        });
        Schema::dropIfExists('vehicles_fuel_entries');
    }
};
