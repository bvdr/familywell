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

    // Language: pick the app locale (per user). Options come from the Translations registry.
    Route::get('settings/language', function () {
        return Inertia::render('settings/language', [
            'locales' => \App\Support\Translations::options(),
            'current' => auth()->user()->locale ?? config('app.locale'),
        ]);
    })->name('language');
    Route::patch('settings/language', function (\Illuminate\Http\Request $request) {
        $data = $request->validate([
            'locale' => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys(\App\Support\Translations::LOCALES))],
        ]);
        $request->user()->update(['locale' => $data['locale']]);

        return back();
    })->name('language.update');

    Route::get('settings/notifications', function () {
        // Categories come from the notifications registry (each module registers its own); merge in
        // the user's saved on/off, defaulting missing ones to on.
        $saved = auth()->user()->notification_prefs ?? [];
        $categories = collect(\Modules\Notifications\NotificationCategories::all())
            ->map(fn ($meta, $id) => [
                'id' => $id,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'on' => (bool) ($saved[$id] ?? true),
            ])->values();

        return Inertia::render('settings/notifications', ['categories' => $categories]);
    })->name('notifications');
});
