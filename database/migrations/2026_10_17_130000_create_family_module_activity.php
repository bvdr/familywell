<?php

use App\Support\Engagement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The paywall comes after real use, not at signup: a family keeps free access until it has added items in
// Engagement::MODULES_NEEDED different modules AND is Billing::GRACE_HOURS old (see Billing::hasAccess).
// family_module_activity: the first time each module got an item, per family (what the admin's bar shows).
// families.engaged_at: when the family reached the module count, so the gate needs no count per request.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_module_activity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('module_id', 64);
            $table->timestamp('created_at');
            $table->unique(['family_id', 'module_id']);
        });
        Schema::table('families', fn (Blueprint $table) => $table->timestamp('engaged_at')->nullable());

        // Existing families: rebuilt from the items they already have, so a family that already used enough
        // modules stays behind the paywall and one that didn't gets free access until it does.
        Engagement::backfill();
    }

    public function down(): void
    {
        Schema::table('families', fn (Blueprint $table) => $table->dropColumn('engaged_at'));
        Schema::dropIfExists('family_module_activity');
    }
};
