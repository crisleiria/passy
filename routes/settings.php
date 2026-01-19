<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\PassKeyController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance.edit');

    Route::get('settings/pin', function () {
        return Inertia::render('settings/Pin');
    })->name('pin.edit');

    Route::get('settings/two-factor', [TwoFactorAuthenticationController::class, 'show'])
        ->name('two-factor.show');

    Route::get('settings/passkeys', [PassKeyController::class, 'edit'])->name('passkeys.edit');
    Route::post('settings/passkeys', [PassKeyController::class, 'store'])->name('passkeys.store');
    
    Route::get('settings/passkeys/register', [PasskeyController::class, 'registerOptions'])->name('passkeys.register-options');

    Route::delete('settings/passkeys/{passkey}', [PassKeyController::class, 'destroy'])->name('passkeys.destroy');
});
