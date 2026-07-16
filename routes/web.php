<?php

use App\Http\Controllers\FamilyMemberController;
use App\Http\Controllers\FamilyModuleController;
use App\Http\Controllers\ModuleAssetController;
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

    Route::get('/m/{module}/frontend.js', [ModuleAssetController::class, 'frontend'])->name('module.frontend');

    Route::get('/family/modules', [FamilyModuleController::class, 'index'])->name('family.modules.index');
    Route::post('/family/modules/{module}/enable', [FamilyModuleController::class, 'enable'])->name('family.modules.enable');
    Route::delete('/family/modules/{module}', [FamilyModuleController::class, 'disable'])->name('family.modules.disable');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
