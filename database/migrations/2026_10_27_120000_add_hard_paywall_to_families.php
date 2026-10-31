<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hard paywall flag (Billing::hasAccess). Families made by the onboarding from now on get true: they can't
        // use the app until they've paid. Default false on purpose, so every family that exists today (and
        // the demo, and families made by seeders / tests) keeps the free-period rule it has now.
        Schema::table('families', function (Blueprint $table) {
            $table->boolean('hard_paywall')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->dropColumn('hard_paywall');
        });
    }
};
