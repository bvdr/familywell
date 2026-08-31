<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('birthdays_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            // One birthdate per person; cascades away with the person (automatic cleanup).
            $table->foreignId('person_id')->constrained('contacts_people')->cascadeOnDelete();
            $table->date('birthdate');
            $table->timestamps();
            $table->unique('person_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birthdays_dates');
    }
};
