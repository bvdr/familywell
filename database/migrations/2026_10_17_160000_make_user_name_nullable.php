<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sign-up is just an email and a 6-digit code now, so a new account has no name until the "About you"
// onboarding step asks for it (OnboardingController::about). Null = not asked yet.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('name')->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('name')->nullable(false)->change());
    }
};
