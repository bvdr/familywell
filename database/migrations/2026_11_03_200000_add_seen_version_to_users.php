<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The newest changelog version this person has opened What's new on (App\Support\Changelog). On the account, not the
// device (design ntvzN, SEEN): reading it on the phone clears the dot on the laptop too.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('seen_version', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('seen_version');
        });
    }
};
