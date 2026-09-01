<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 4: the vehicle's permanent history + paperwork + notes. Three separate concerns, all family-scoped:
//
//  1) REPAIRS. Unplanned fixes — conceptually SEPARATE from the recurring maintenance schedules of P2/P3.
//     A repair is a one-off event that happened (what/when/at-what-mileage/what-it-cost/where), forming the
//     car's permanent history. A repair with a cost can mirror into Spending exactly like a maintenance
//     completion does (reusing the P3 VehicleExpenseLink bridge — no new Spending coupling). Attachments
//     (invoice / photo) hang off the repair via a small child table, stored on R2 through StoredImage.
//
//  2) DOCUMENTS. Registration, insurance, inspection, warranty, invoices, manual, road tax… Each has a
//     type, an optional expiry date, and an attached PDF/photo (single file per document, on R2). A
//     document whose expiry is approaching can spawn ONE renewal Task in the existing Tasks module —
//     idempotent via `renewal_task_id` (the same open-task-id pattern maintenance uses), so the daily sweep
//     and the manual button never create a duplicate.
//
//  3) NOTES. Reuse the existing Notes module rather than a bespoke notes engine: a note is a real
//     notes_notes row (markdown-lite, shareable), and this link table attaches it to a vehicle. Owned by
//     Vehicles, so the Notes module stays unaware of vehicles — the link is the sole bridge.
return new class extends Migration
{
    public function up(): void
    {
        // ---- Repairs (unplanned, permanent history) ---------------------------------------------------
        Schema::create('vehicles_repairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles_vehicles')->cascadeOnDelete();

            $table->string('title');                              // what was fixed ("Replaced alternator")
            $table->date('occurred_on');                          // when it happened
            $table->unsignedInteger('mileage')->nullable();       // odometer at the time (optional)
            $table->decimal('cost', 12, 2)->nullable();           // total, mirrored into Spending when set
            $table->string('provider')->nullable();               // garage / who did it (free text)
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'occurred_on']);
        });

        // 0..n attachments per repair (invoice/photo). Same R2 + StoredImage mechanism as spend receipts;
        // `path` is the object key, `name` keeps the original filename so a PDF downloads with a sane name.
        Schema::create('vehicles_repair_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repair_id')->constrained('vehicles_repairs')->cascadeOnDelete();
            $table->string('path');
            $table->string('name')->nullable();
            $table->timestamps();
        });

        // ---- Documents (type + expiry + single file) --------------------------------------------------
        Schema::create('vehicles_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles_vehicles')->cascadeOnDelete();

            // type validates against a code list (see VehicleDocument::TYPES) but is stored as a plain
            // string so adding a type is a code change, not a migration; an unknown value still renders via
            // the client's fallback label.
            $table->string('type')->default('other');
            $table->string('title');
            $table->date('expires_on')->nullable();               // null = doesn't expire (e.g. manual)
            $table->string('file_path')->nullable();              // R2 object key (PDF or photo), nullable
            $table->string('file_name')->nullable();              // original filename for downloads
            $table->text('notes')->nullable();

            // The currently-open renewal Task this document spawned when its expiry approached. Non-null ==
            // "there's already a renewal task", so the sweep + the manual button skip it (idempotency key).
            // Plain id, no FK to tasks_tasks — the link is code-managed (DocumentRenewalTasks verifies the
            // task still exists and is open), keeping the two modules loosely coupled. Cleared/self-healed
            // when the task is completed or deleted, so a later expiry cycle can re-fire.
            $table->unsignedBigInteger('renewal_task_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'expires_on']);
            $table->index('renewal_task_id');
        });

        // ---- Notes (attach an existing-module Note to a vehicle) --------------------------------------
        Schema::create('vehicles_note_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles_vehicles')->cascadeOnDelete();
            // The Notes-module note this attaches. cascadeOnDelete: deleting the note (in Notes) drops the
            // link too, so a vehicle never lists a note that no longer exists.
            $table->foreignId('note_id')->constrained('notes_notes')->cascadeOnDelete();
            $table->timestamps();

            $table->unique('note_id');            // a note belongs to at most one vehicle
            $table->index(['vehicle_id', 'family_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles_note_links');
        Schema::dropIfExists('vehicles_documents');
        Schema::dropIfExists('vehicles_repair_attachments');
        Schema::dropIfExists('vehicles_repairs');
    }
};
