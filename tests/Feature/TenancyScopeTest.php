<?php

use App\Models\Family;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A throwaway tenant-owned model for the test.
beforeEach(function () {
    Schema::create('notes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('family_id');
        $table->string('body');
        $table->timestamps();
    });
});

it('hides rows from other families and auto-fills family_id', function () {
    $famA = Family::factory()->create();
    $famB = Family::factory()->create();

    $model = new class extends Model {
        protected $table = 'notes';
        protected $guarded = [];
        use \App\Support\Tenancy\BelongsToFamily;
    };

    CurrentFamily::set($famA->id);
    $model->newInstance(['body' => 'A-note'])->save();

    CurrentFamily::set($famB->id);
    $model->newInstance(['body' => 'B-note'])->save();

    // Scoped to B now: only B-note visible, and it was auto-stamped with famB.
    expect($model->newQuery()->pluck('body')->all())->toBe(['B-note']);
    expect($model->newQuery()->first()->family_id)->toBe($famB->id);

    CurrentFamily::set($famA->id);
    expect($model->newQuery()->pluck('body')->all())->toBe(['A-note']);
});
