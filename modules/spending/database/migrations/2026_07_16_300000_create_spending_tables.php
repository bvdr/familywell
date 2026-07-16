<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// All tables are prefixed `spending_`: the module system forbids a module from touching tables
// outside its own namespace (ModuleNamespace, spec 12.3).
return new class extends Migration
{
    public function up(): void
    {
        // A shared expense tracker: an "event" (trip/budget) holds spends split among its members.
        Schema::create('spending_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->string('name');
            $table->string('currency', 3)->default('EUR');
            $table->foreignId('created_by');
            $table->timestamps();
        });

        // Snapshot of who participates in an event (defaults to the family at creation time), so
        // splits stay stable even if family membership later changes.
        Schema::create('spending_event_user', function (Blueprint $table) {
            $table->foreignId('spend_event_id')->constrained('spending_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['spend_event_id', 'user_id']);
        });

        Schema::create('spending_spends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->foreignId('spend_event_id')->constrained('spending_events')->cascadeOnDelete();
            $table->foreignId('payer_id')->comment('the member who paid');
            // Money is stored in minor units (cents) as an integer, never a float, so splits and
            // balances are exact.
            $table->bigInteger('amount');
            $table->string('category')->default('other');
            $table->string('note')->nullable();
            $table->date('spent_at');
            $table->foreignId('created_by');
            $table->timestamps();
        });

        // A recorded payment between two members ("mark paid"), which shifts the balances.
        Schema::create('spending_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->foreignId('spend_event_id')->constrained('spending_events')->cascadeOnDelete();
            $table->foreignId('from_user_id');
            $table->foreignId('to_user_id');
            $table->bigInteger('amount');
            $table->foreignId('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spending_settlements');
        Schema::dropIfExists('spending_spends');
        Schema::dropIfExists('spending_event_user');
        Schema::dropIfExists('spending_events');
    }
};
