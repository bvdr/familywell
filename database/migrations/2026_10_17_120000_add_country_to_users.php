<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The country a person last used the app from (ISO 3166-1 alpha-2), for the admin. Filled by TrackLastSeen
// from Cloudflare's CF-IPCountry header on web requests; existing users get it on their next visit.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('country', 2)->nullable());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('country'));
    }
};
