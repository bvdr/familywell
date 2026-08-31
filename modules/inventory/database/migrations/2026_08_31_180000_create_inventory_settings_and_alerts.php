<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 5 schema: the family's inventory settings (one row per family, typed columns like
// spending_settings) and the alert ledger the daily `inventory:check` uses to stay idempotent.
return new class extends Migration
{
    public function up(): void
    {
        // One settings row per family. Typed columns (not a key/value bag), mirroring
        // Modules\Spending\SpendSetting: family_id is unique so InventorySetting::current() can
        // firstOrCreate the singleton. All booleans default to the "sensible on" the spec asks for, so a
        // family that never opens Settings still gets the calm defaults (auto-replenish + all alerts on).
        Schema::create('inventory_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->unique()->constrained()->cascadeOnDelete();

            // Default measurement system. No app-wide unit setting exists (grepped: none in app/,
            // modules/, config/), so this is the module-level default the spec calls for — NOT a competing
            // global. The per-item unit list (InventoryItem::UNITS) is currently metric/neutral; this
            // records the family's declared system for when imperial units are added.
            $table->string('unit_system', 16)->default('metric'); // metric | imperial

            // Replenishment: whether the daily check auto-surfaces short items onto Shopping. Kept as two
            // switches (low vs empty) so a family can auto-buy only when fully out, or as soon as low.
            $table->boolean('auto_add_low')->default(true);   // add low items to Shopping automatically
            $table->boolean('auto_add_empty')->default(true); // add empty items to Shopping automatically

            // Notification policy — which conditions the daily check may notify about (a FAMILY-level
            // policy). Orthogonal to the per-USER push mute on users.notification_prefs: this decides
            // whether an alert is generated at all; that decides whether a given member gets the push.
            $table->boolean('notify_low')->default(true);
            $table->boolean('notify_empty')->default(true);
            $table->boolean('notify_expired')->default(true);
            $table->boolean('notify_stale')->default(true);

            $table->timestamps();
        });

        // The alert ledger: one row per (item, condition) recording the last day we notified about it.
        // The daily check notifies only when last_notified_on != today, so re-running the command on the
        // same day never double-fires (idempotent per condition/day), while a condition that is still true
        // tomorrow nudges again. Rows are pruned when the condition clears, so the table stays small and a
        // condition that clears then returns notifies fresh.
        Schema::create('inventory_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->string('condition'); // low | empty | expired | stale
            $table->date('last_notified_on')->nullable();
            $table->timestamps();

            // One live alert per condition per item — updateOrCreate keys on this.
            $table->unique(['inventory_item_id', 'condition']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_alerts');
        Schema::dropIfExists('inventory_settings');
    }
};
