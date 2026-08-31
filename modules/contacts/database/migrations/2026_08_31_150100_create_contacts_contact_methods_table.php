<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts_contact_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            // Cascades away with the person, so deleting a contact cleans up its methods.
            $table->foreignId('person_id')->constrained('contacts_people')->cascadeOnDelete();
            $table->string('type');            // phone | email | address | url | other
            $table->string('label')->nullable(); // e.g. "work", "home"
            $table->string('value');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index('person_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts_contact_methods');
    }
};
