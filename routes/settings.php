<?php

use App\Http\Controllers\Settings\NotificationController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\TelegramController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Guided bot setup: BotFather → paste token → press Start → connected.
    Route::get('settings/telegram', [TelegramController::class, 'edit'])->name('telegram.edit');
    Route::post('settings/telegram/token', [TelegramController::class, 'storeToken'])
        ->middleware('throttle:10,1')
        ->name('telegram.token');
    Route::post('settings/telegram/detect', [TelegramController::class, 'detect'])
        ->middleware('throttle:20,1')
        ->name('telegram.detect');
    Route::post('settings/telegram/webhook', [TelegramController::class, 'registerWebhook'])
        ->middleware('throttle:20,1')
        ->name('telegram.webhook-register');
    Route::delete('settings/telegram', [TelegramController::class, 'destroy'])->name('telegram.destroy');
    Route::patch('settings/telegram/dismiss', [TelegramController::class, 'dismissPrompt'])
        ->name('telegram.dismiss');

    // PWA push notifications (enable/disable is client-side; this hosts it).
    Route::get('settings/notifications', [NotificationController::class, 'edit'])->name('notifications.edit');
    Route::patch('settings/notifications/dismiss', [NotificationController::class, 'dismissPrompt'])
        ->name('notifications.dismiss');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
