<?php

use App\Http\Controllers\Settings\MediaLibraryController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    // Media library needs the active family (to list its uploads), so it also runs the family middleware.
    Route::middleware('family')->group(function () {
        Route::get('settings/media', [MediaLibraryController::class, 'index'])->name('media');
        Route::delete('settings/media/{type}/{id}', [MediaLibraryController::class, 'destroy'])->name('media.destroy');
    });

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');
});
