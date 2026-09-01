<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// V1.1 Phase 2: SHOPPING FOR PARTS — "add a part to Shopping" from a vehicle (or, optionally, from a
// maintenance schedule / repair). Each request is a row here that mirrors into the existing Shopping module
// as a real shopping item labelled with the car ("Bosch brake pads — Dacia Duster"), reusing the same
// shopping.item.bought hook Inventory already added.
//
// `shopping_item_id` is the cross-module link into the Shopping row: a plain nullable id (no FK — Shopping
// lives in another module, integrity is code-managed, exactly like inventory_items.shopping_item_id). It is
// both the idempotency pointer (never create a second shopping row for a still-open request) and what the
// bought listener matches on. `maintenance_schedule_id` / `repair_id` are intra-module FKs recording the
// part's origin when it was requested from a specific service. `estimated_cost`, when set, is what a
// purchase mirrors into Spending (a shopping item carries no price, so an explicit estimate is how the
// bought -> vehicle-spending link stays clean).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles_part_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles_vehicles')->cascadeOnDelete();

            $table->string('name');                                   // the part, e.g. "Bosch brake pads"
            $table->decimal('estimated_cost', 12, 2)->nullable();     // optional; drives the bought->Spending mirror
            $table->string('status')->default('requested');          // requested | bought
            $table->date('bought_on')->nullable();                   // stamped when the shopping row is bought

            // Cross-module link to the Shopping row (plain id, no FK — code-managed, like inventory). The
            // idempotency pointer AND what the shopping.item.bought listener matches on.
            $table->unsignedBigInteger('shopping_item_id')->nullable()->index();

            // Optional origin — the service this part is for (intra-module, so real FKs; null for a general
            // vehicle-level part). nullOnDelete so deleting the schedule/repair keeps the parts history.
            $table->foreignId('maintenance_schedule_id')->nullable()
                ->constrained('vehicles_maintenance_schedules')->nullOnDelete();
            $table->foreignId('repair_id')->nullable()
                ->constrained('vehicles_repairs')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'status']);
        });

        Schema::table('vehicles_expense_links', function (Blueprint $table) {
            // Which part request produced this expense, when a bought part with an estimated cost mirrored
            // into Spending (null for a maintenance completion, repair, fuel fill or ad-hoc expense).
            $table->foreignId('part_request_id')->nullable()->after('fuel_entry_id')
                ->constrained('vehicles_part_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles_expense_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('part_request_id');
        });
        Schema::dropIfExists('vehicles_part_requests');
    }
};
