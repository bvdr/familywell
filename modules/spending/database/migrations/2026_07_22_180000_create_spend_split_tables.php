<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Weighted / custom expense splitting. Two per-event tables, both keyed by a participant "key"
// ('u{userId}' for family members, 'g{guestId}' for guests) so members and guests are handled
// uniformly. Absence of a row means the default (weight 1 / split among everyone equally), so these
// only store overrides and existing events keep behaving exactly as before.
return new class extends Migration
{
    public function up(): void
    {
        // Per-participant split settings for one event: headcount (how many people they pay for) and
        // an optional "covered_by" (another participant whose balance absorbs this one's share).
        Schema::create('spending_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spend_event_id')->constrained('spending_events')->cascadeOnDelete();
            $table->string('participant_key'); // u{userId} | g{guestId}
            $table->unsignedInteger('weight')->default(1);
            $table->string('covered_by')->nullable(); // participant_key of the payer covering this one
            $table->timestamps();
            $table->unique(['spend_event_id', 'participant_key']);
        });

        // Per-spend split override: which participants share a given spend, and optionally the exact
        // amount each owes (in the spend's BASE currency, minor units). No rows => split among all event
        // participants (weighted). Rows with null amount => weighted split among just those participants.
        // Rows with an amount => exact split (amounts sum to the spend's base_amount).
        Schema::create('spending_spend_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spend_id')->constrained('spending_spends')->cascadeOnDelete();
            $table->string('participant_key');
            $table->bigInteger('amount')->nullable(); // base-currency cents, or null for a weighted share
            $table->timestamps();
            $table->unique(['spend_id', 'participant_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spending_spend_shares');
        Schema::dropIfExists('spending_participants');
    }
};
