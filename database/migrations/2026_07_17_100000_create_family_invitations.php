<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Pending email invitations to join a family. Accepting attaches the user with the invited role and
// deletes the row.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role')->default('member'); // admin | member
            $table->string('token', 64)->unique();      // bearer token in the accept link
            $table->foreignId('invited_by')->constrained('users');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // One pending invite per email per family.
            $table->unique(['family_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_invitations');
    }
};
