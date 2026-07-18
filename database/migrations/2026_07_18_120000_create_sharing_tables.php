<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Core, polymorphic sharing. Any entity (spend events now, other module entities later) opts in via
// the Shareable trait; these tables are app-core owned, so modules read them through the trait but
// never migrate them (ModuleNamespace only constrains module-owned migrations).
return new class extends Migration
{
    public function up(): void
    {
        // Per-entity visibility: who, by default, can reach the entity.
        //   family  = anyone in the entity's family (default)
        //   invited = only people with an explicit share row
        //   private = owner only
        Schema::create('share_settings', function (Blueprint $table) {
            $table->id();
            $table->morphs('shareable'); // shareable_type + shareable_id (+ index)
            $table->string('visibility')->default('family');
            $table->timestamps();
            $table->unique(['shareable_type', 'shareable_id']);
        });

        // Explicit per-user grant. Overrides the visibility default (e.g. downgrade one family member
        // to viewer, or grant an invited person access). role = owner | editor | viewer.
        Schema::create('shares', function (Blueprint $table) {
            $table->id();
            $table->morphs('shareable');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('viewer');
            $table->foreignId('invited_by')->nullable();
            $table->timestamps();
            $table->unique(['shareable_type', 'shareable_id', 'user_id']);
        });

        // Tokenized read-only link. Anyone with the token can view the entity, no account needed.
        // The token is a bearer secret, revocable by deleting the row.
        Schema::create('share_links', function (Blueprint $table) {
            $table->id();
            $table->morphs('shareable');
            $table->string('token', 64)->unique();
            $table->string('role')->default('viewer');
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_links');
        Schema::dropIfExists('shares');
        Schema::dropIfExists('share_settings');
    }
};
