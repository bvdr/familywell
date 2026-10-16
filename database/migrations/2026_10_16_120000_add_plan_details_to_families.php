<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What Settings > Billing's Plan card shows: the plan (monthly / yearly), when the current period ends,
// and whether it renews or ends then. Written by Billing::setStatus() from the Stripe subscription on
// every checkout sync + customer.subscription.* webhook, so the page never calls Stripe to render.
// Families subscribed before this column existed are filled in once on their next Billing visit.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->string('subscription_plan', 20)->nullable();
            $table->timestamp('subscription_period_end')->nullable();
            $table->boolean('subscription_canceling')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('families', fn (Blueprint $table) => $table->dropColumn(['subscription_plan', 'subscription_period_end', 'subscription_canceling']));
    }
};
