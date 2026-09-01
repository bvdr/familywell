<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// V1.1 Phase 2: TIRES — each vehicle keeps one or more tyre sets (a summer set, a winter set, an all-season
// set…), each with its own lifecycle: brand, size, tread depth, where it's stored off-season and — the key
// invariant — whether it is the set CURRENTLY mounted on the car. Only one set per vehicle may be mounted at
// a time; the "mount this set" action swaps that flag (enforced in the controller, inside a transaction) and
// stamps when/at-what-mileage it went on, which is what the Timeline reads to show a "Changed to winter tyres"
// event.
//
// `open_task_id` is the seasonal-reminder link into the Tasks module: creating a "Change to winter tyres"
// task records its id here so a second identical open task is never spawned (same idempotency pattern the
// maintenance schedules use with their own open_task_id — a plain id, no FK, integrity managed in code).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles_tire_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles_vehicles')->cascadeOnDelete();

            // summer | winter | all_season. A plain string (validated against VehicleTireSet::SEASONS in code,
            // like the fuel_type list) so adding a season later is a one-line change and an unknown value still
            // renders via the client's fallback label.
            $table->string('season');
            $table->string('brand')->nullable();
            $table->string('size')->nullable();                       // e.g. "225/55 R17" (free text)
            $table->date('installed_on')->nullable();                 // when this set was last mounted
            $table->unsignedInteger('installed_mileage')->nullable(); // odometer when it went on
            $table->decimal('tread_depth_mm', 4, 1)->nullable();      // current tread, mm
            $table->string('storage_location')->nullable();           // where the off-car set lives (free text)
            // The invariant: at most one mounted set per vehicle (enforced in the controller's swap action).
            $table->boolean('is_mounted')->default(false);
            $table->text('notes')->nullable();
            // Seasonal-reminder link into Tasks (plain id, no FK — cross-module, code-managed; mirrors the
            // maintenance schedule's open_task_id idempotency key).
            $table->unsignedBigInteger('open_task_id')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'is_mounted']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles_tire_sets');
    }
};
