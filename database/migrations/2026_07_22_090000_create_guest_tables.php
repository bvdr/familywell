<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Core, polymorphic guest sharing. Any entity opts in via the GuestShareable trait: it can be opened
// by people WITHOUT an account through an unguessable link (optionally password-gated), where each
// guest is just a name with a role. App-core owned (like share_settings/shares/share_links); modules
// read these through the trait but never migrate them.
return new class extends Migration
{
    public function up(): void
    {
        // One unguessable link per entity. token = 48-char bearer secret; password = optional bcrypt
        // hash (null = shared freely). Revoke by deleting the row.
        Schema::create('guest_links', function (Blueprint $table) {
            $table->id();
            $table->morphs('guestable'); // guestable_type + guestable_id (+ index)
            $table->string('token', 48)->unique();
            $table->string('password')->nullable();
            $table->foreignId('created_by');
            $table->timestamps();
            $table->unique(['guestable_type', 'guestable_id']); // one link per entity
        });

        // A named participant with no user account. role = viewer (read-only) | editor (can write).
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->morphs('guestable');
            $table->string('name');
            $table->string('role')->default('editor');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guests');
        Schema::dropIfExists('guest_links');
    }
};
