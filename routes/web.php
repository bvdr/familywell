<?php

use App\Http\Controllers\FamilyMemberController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::get('/family/members', [FamilyMemberController::class, 'index'])->name('family.members.index');
    Route::post('/family/members', [FamilyMemberController::class, 'store'])->name('family.members.store');
    Route::delete('/family/members/{user}', [FamilyMemberController::class, 'destroy'])->name('family.members.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
