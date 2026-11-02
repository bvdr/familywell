<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Install the app" setup started from the browser nudge, outside the onboarding: 'install' or 'notifications'
// while it's under way, null otherwise. On the account, not the device: on an iPhone the installed app doesn't share
// storage with Safari, so only the server can tell the new Home Screen app where the person was.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('setup_step', 20)->nullable()->after('onboarding_step');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('setup_step');
        });
    }
};
