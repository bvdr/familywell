<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where a new family's creator is in the onboarding after step 1 ('invite', 'modules', ...). Null = not in
        // onboarding, which is every existing account. RequireFamily sends them back here until it's done.
        Schema::table('users', function (Blueprint $table) {
            $table->string('onboarding_step', 20)->nullable();
        });

        // A null email is the family's shareable join link ("Or share a link instead"): anyone with it joins, and it
        // isn't used up by joining. The (family_id, email) unique still holds one invite per address; NULLs don't
        // collide, and the app keeps one link per family.
        Schema::table('family_invitations', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding_step');
        });
    }
};
