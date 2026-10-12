<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Comped access as a first-class flag instead of a faked Stripe status. `access_override = 'lifetime'`
// (nullable) is honoured by Billing::hasAccess() and never touched by the Stripe paths (checkout sync +
// webhooks only write subscription_status / stripe_customer_id), so a webhook can't revoke it. Granted
// and revoked by platform admins (admin API / `family:lifetime`).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->string('access_override', 20)->nullable();
        });

        // Families that were "activated" by hand before this flag existed: a paid status with no Stripe
        // customer can only have been set manually (every real subscription has a customer; on prod that
        // is exactly the Dragomir family, 2026-10-10). Move them to the flag and clear the fake status.
        DB::table('families')
            ->whereIn('subscription_status', ['active', 'trialing', 'past_due'])
            ->whereNull('stripe_customer_id')
            ->where('is_demo', false)
            ->update(['access_override' => 'lifetime', 'subscription_status' => null]);
    }

    public function down(): void
    {
        // Put the hand-set status back so access survives a rollback, then drop the column.
        DB::table('families')->where('access_override', 'lifetime')->whereNull('stripe_customer_id')
            ->update(['subscription_status' => 'active']);
        Schema::table('families', fn (Blueprint $table) => $table->dropColumn('access_override'));
    }
};
