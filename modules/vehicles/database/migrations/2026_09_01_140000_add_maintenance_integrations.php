<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 3: wire the two cross-module maintenance workflows.
//
//  1) MAINTENANCE -> TASKS. A due schedule spawns ONE task in the existing Tasks module; the schedule
//     remembers which task it opened in `open_task_id` so the same due schedule never spawns a duplicate
//     (the idempotency key). Cleared when the maintenance is completed, so the next cycle can re-fire.
//     Deliberately NO database FK to tasks_tasks: the link is code-managed (MaintenanceTasks verifies the
//     task still exists and is open), which keeps the two modules loosely coupled and survives a task
//     being deleted out from under the schedule.
//
//  2) MAINTENANCE -> SPENDING. A completion with a cost records a real Spend in the existing Spending
//     module; the vehicle reference lives in this module's OWN link table (vehicles_expense_links) rather
//     than a new column on spending_spends, so the Spending schema/code is left completely untouched — the
//     "specialized module owns the link, the core module owns its concern" philosophy. The link is what
//     lets Spending answer "how much on this car" (join spends through it, filtered by vehicle_id).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles_maintenance_schedules', function (Blueprint $table) {
            // The currently-open Tasks task this schedule spawned when it fell due. Non-null == "there is
            // already an open task for this maintenance", so the due sweep skips it (idempotent). Plain id,
            // no FK — see the file header.
            $table->unsignedBigInteger('open_task_id')->nullable()->after('active');
            $table->index('open_task_id');
        });

        // One row per car-expense Spend: which vehicle it belongs to, and (optionally) which performed-
        // maintenance record it came from. Owned by Vehicles, so it lives in the vehicles_ namespace and
        // the Spending module never learns about vehicles.
        Schema::create('vehicles_expense_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles_vehicles')->cascadeOnDelete();
            // The Spend this links to. cascadeOnDelete: deleting the expense in Spending drops the link too,
            // so a car's total never counts a spend that no longer exists.
            $table->foreignId('spend_id')->constrained('spending_spends')->cascadeOnDelete();
            // The service that generated the expense, when it came from a completion. Nullable for an
            // ad-hoc "Add expense" that isn't tied to a specific performance; nullOnDelete so pruning
            // history keeps the expense (it's still the car's money) — just unlinked from the record.
            $table->foreignId('maintenance_history_id')->nullable()
                ->constrained('vehicles_maintenance_history')->nullOnDelete();
            $table->timestamps();

            $table->unique('spend_id');           // at most one vehicle link per spend
            $table->index(['vehicle_id', 'family_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles_expense_links');
        Schema::table('vehicles_maintenance_schedules', function (Blueprint $table) {
            $table->dropIndex(['open_task_id']);
            $table->dropColumn('open_task_id');
        });
    }
};
