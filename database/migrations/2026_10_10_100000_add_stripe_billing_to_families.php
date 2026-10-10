<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The subscription belongs to the family (one payer, every member gets access). We mirror only what
// the paywall needs: the Stripe customer (for the billing portal) and the subscription's status, kept
// current by the Stripe webhook (App\Http\Controllers\BillingController::webhook).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('subscription_status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->dropColumn(['stripe_customer_id', 'subscription_status']);
        });
    }
};
