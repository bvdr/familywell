<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 'basic' | 'pro': which tier the family's subscription is on (Billing::tier, read from its price), kept like
        // subscription_plan. Null = not known yet / never subscribed, which reads as basic (the only tier before Pro).
        Schema::table('families', function (Blueprint $table) {
            $table->string('subscription_tier', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->dropColumn('subscription_tier');
        });
    }
};
