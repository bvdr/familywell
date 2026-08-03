<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-user "last opened" log for shareable entities, powering the dashboard's "Pick up where you
// left off" (ordered by recency, dropping anything not opened in the last few days). One row per
// (user, entity); the visit time is refreshed on each open. Type = the ShareableRegistry slug
// (project, task_list, shopping_list, spend_event) so it stays module-agnostic.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('visitable_type');
            $table->unsignedBigInteger('visitable_id');
            $table->timestamp('visited_at');
            // One row per user+entity; opening again just bumps visited_at.
            $table->unique(['user_id', 'visitable_type', 'visitable_id']);
            // The dashboard query filters by user and recency.
            $table->index(['user_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_visits');
    }
};
