<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Vehicles module's schema. Everything is family-scoped (BelongsToFamily). A vehicle is the object
// at the centre of the module; later phases hang maintenance schedules, repairs, fuel, documents and
// notes off it. Phase 1 owns the vehicle itself + its first-class mileage: `current_mileage` is the
// odometer the whole UI reads, and every change to it also appends a `vehicles_mileage_log` row so the
// history is never derived from guesswork. `status`/`next service` seen in the cards are NOT stored —
// they are placeholders in P1 and become derived values once maintenance schedules land in P2.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();

            // Identity. make/model/year are the headline; version is the trim/engine free text
            // ("1.5 dCi", "2.0 CDTI"). fuel_type/transmission validate against a code list (see the
            // model) but are stored as plain strings so adding an option is a code change, not a
            // migration, and an unknown value still renders via the client's fallback label.
            $table->string('make');
            $table->string('model');
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('version')->nullable();               // trim / engine, free text
            $table->string('fuel_type')->nullable();             // petrol/diesel/hybrid/electric/lpg/cng/other
            $table->string('transmission')->nullable();          // manual/automatic/other
            $table->string('vin')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('color')->nullable();

            // Ownership + provenance (metadata references, not permission gates — every family member
            // may edit any family vehicle, exactly like inventory stock; the family scope is the boundary).
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('primary_driver_id')->nullable()->constrained('users')->nullOnDelete();

            // The odometer. `current_mileage` is the single number every screen reads; it is kept in
            // step with vehicles_mileage_log on every update (never edited directly without a log row).
            $table->unsignedInteger('current_mileage')->default(0);

            // Optional cover photo (R2 object key, family-namespaced, served through an auth-gated route —
            // same StoredImage pattern as project covers and spend receipts).
            $table->string('photo_path')->nullable();

            // Optional obligations metadata surfaced on the card. In P1 these are plain fields the user
            // fills in; P4/P5 turn insurance/inspection/road-tax into first-class obligations with
            // expiry -> Task/Notification. Kept as strings so they can hold "valid until Mar 2027" etc.
            $table->string('license_plate')->nullable();
            $table->string('insurance_company')->nullable();
            $table->string('road_tax_status')->nullable();
            $table->string('inspection_status')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['family_id', 'make']);
        });

        // Append-only odometer history. One row per recorded mileage; `recorded_at` is when the reading
        // was taken (defaults to now, but can be back-dated on a manual correction). Every write that
        // moves `vehicles.current_mileage` — including the opening reading at creation — writes a row
        // here, so the history and the odometer can never disagree.
        Schema::create('vehicles_mileage_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles_vehicles')->cascadeOnDelete();
            $table->unsignedInteger('mileage');                  // the absolute odometer reading
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles_mileage_log');
        Schema::dropIfExists('vehicles_vehicles');
    }
};
