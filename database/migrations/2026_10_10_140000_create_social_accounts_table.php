<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Sign in with Google / Apple" links (App\Support\Auth\SocialLogin). Identity only: we keep the provider's
// stable user id (`sub`) and the email it reported when linked, never tokens or the profile picture.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16); // 'google' | 'apple'
            $table->string('provider_user_id');
            // Informational (shown in Settings): Apple may hand out a private relay address, and either
            // provider's email can change later; matching always goes through provider_user_id.
            $table->string('email')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
