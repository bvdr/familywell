<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('todos_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->string('title');
            $table->boolean('done')->default(false);
            $table->string('visibility')->default('shared'); // 'shared' | 'private'
            $table->foreignId('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todos_items');
    }
};
