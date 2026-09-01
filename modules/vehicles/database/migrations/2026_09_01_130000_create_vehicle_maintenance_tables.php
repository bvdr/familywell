<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 2: the maintenance schedule engine. A schedule is a RECURRING maintenance rule for one vehicle
// with two simultaneous triggers — every `interval_km` kilometres AND every `interval_months` months —
// and it becomes due when EITHER is reached (the due logic lives in Modules\Vehicles\MaintenanceStatus).
// A history row is one PERFORMED maintenance record; completing a schedule writes a history row and
// advances the schedule's `last_performed_*`, from which the next due point is recomputed. History can
// also exist without a schedule (an ad-hoc, one-off service), hence the nullable schedule_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles_maintenance_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles_vehicles')->cascadeOnDelete();

            // What this rule is. `name` is the display label ("Oil & filter change"); `type` is the
            // template key it was created from ("oil_change") or "custom" — kept so the UI can group /
            // icon by template and so template defaults can be re-applied, but stored as a plain string
            // (adding a template is a code change, never a migration).
            $table->string('name');
            $table->string('type')->default('custom');

            // The two triggers. AT LEAST ONE is always set (enforced in the controller): a schedule may be
            // km-only (brake pads), time-only (wipers) or both (oil change). Nullable = "this trigger is
            // not used for this rule".
            $table->unsignedInteger('interval_km')->nullable();     // e.g. every 15,000 km
            $table->unsignedSmallInteger('interval_months')->nullable();   // e.g. every 12 months

            // The baseline the next-due point is measured from: when/at-what-odometer it was last done.
            // Both null = never performed (surfaced as "due — needs its first record"). Advanced on every
            // "mark as completed".
            $table->date('last_performed_at')->nullable();
            $table->unsignedInteger('last_performed_mileage')->nullable();

            $table->text('notes')->nullable();
            // Inactive schedules are retained (history stays meaningful) but drop out of the due summary
            // and the vehicle's status — a soft archive, not a delete.
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'active']);
        });

        Schema::create('vehicles_maintenance_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles_vehicles')->cascadeOnDelete();
            // Which schedule this performance belongs to. Nullable so a one-off service (no recurring rule)
            // can still be recorded, and so deleting a schedule doesn't erase the car's real service
            // history — the row stays, just unlinked.
            $table->foreignId('schedule_id')->nullable()
                ->constrained('vehicles_maintenance_schedules')->nullOnDelete();

            // A snapshot of what was done — copied from the schedule at completion time so the history row
            // stays truthful even if the schedule is later renamed or removed.
            $table->string('name');
            $table->date('performed_at');
            $table->unsignedInteger('mileage');
            // Cost + provider feed the P3 Spending integration later; for now they are plain fields on the
            // record (provider/garage is free text until the P4/V1.1 service-providers entity exists).
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('provider')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'performed_at']);
            $table->index(['schedule_id', 'performed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles_maintenance_history');
        Schema::dropIfExists('vehicles_maintenance_schedules');
    }
};
