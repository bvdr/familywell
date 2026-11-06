<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Days to remember (design board 10c): one row per yearly date. Like birthdays, the recurring part is month + day
// and the year is optional (it only adds "12 years"). remind_days null = no reminder (design: reminders start off),
// 0 = on the day; reminded_on is the idempotency ledger for that push. person_ids are the contacts linked to the
// day ("Call family" on Home).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('days_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('tone', 16)->default('celebrate'); // celebrate | quiet
            $table->string('icon', 40)->default('heart');
            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('day');
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('note', 500)->nullable();
            // ponytail: a JSON list, not a pivot. Only read to show names and phones on the day; make it a pivot
            // when a contact's card has to list its days (design board "NEW").
            $table->json('person_ids')->nullable();
            $table->unsignedTinyInteger('remind_days')->nullable();
            $table->date('reminded_on')->nullable();
            $table->timestamps();
            $table->softDeletes(); // 30-day trash
            $table->index(['family_id', 'month', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('days_days');
    }
};
