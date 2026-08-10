<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Laravel's standard notifications table, used as the in-app notification log (the `database` channel
// on PushAlert writes here). One row per recipient gives each person their own access-filtered feed
// with built-in read_at / markAsRead. It is core (unprefixed) rather than a `notifications_`-prefixed
// module table because Laravel's DatabaseNotification model is hard-wired to this table name.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
