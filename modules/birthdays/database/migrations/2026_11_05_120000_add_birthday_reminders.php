<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Birthday reminders ahead of the day (design 10b, M6-M12). One family default (how many days ahead, and the hour
// it arrives) in birthdays_settings; a person can differ through birthdays_dates.remind_days (null = the family's).
// 0 = "On the day": the existing morning wish already covers that, so no second push. reminded_on is the
// idempotency ledger for the advance reminder, like notified_on is for the wish.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('birthdays_dates', function (Blueprint $table) {
            $table->unsignedTinyInteger('remind_days')->nullable()->after('birthdate');
            $table->date('reminded_on')->nullable()->after('notified_on');
        });
        Schema::create('birthdays_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('remind_days')->default(3);
            $table->unsignedTinyInteger('send_hour')->default(9); // family-local hour (FamilyClock)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birthdays_settings');
        Schema::table('birthdays_dates', function (Blueprint $table) {
            $table->dropColumn(['remind_days', 'reminded_on']);
        });
    }
};
