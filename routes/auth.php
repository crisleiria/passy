<?php

use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Auth\WebAuthnAuthController;
use App\Http\Controllers\Settings\PassKeyController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    // Traditional auth (kept for fallback)
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store'])
        ->name('register.store');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');

    // WebAuthn API routes
    Route::post('auth/webauthn/register-options', [WebAuthnAuthController::class, 'registerOptions'])
        ->name('webauthn.register.options');

    Route::post('auth/webauthn/register', [WebAuthnAuthController::class, 'register'])
        ->name('webauthn.register');

    Route::post('auth/webauthn/login-options', [WebAuthnAuthController::class, 'loginOptions'])
        ->name('webauthn.login.options');

    Route::post('auth/webauthn/login', [WebAuthnAuthController::class, 'login'])
        ->name('webauthn.login');

    Route::post('auth/webauthn/login-master-key', [WebAuthnAuthController::class, 'loginWithMasterKey'])
        ->name('webauthn.login.masterkey');

    // Old passkey routes (for backward compatibility)
    Route::get('settings/passkeys/authenticate-options', [PasskeyController::class, 'authenticateOptions'])
        ->name('passkeys.authenticate-options');

    Route::post('passkeys/authenticate', [PasskeyController::class, 'authenticate'])
        ->name('passkeys.authenticate');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // Vault encryption data
    Route::get('api/vault/encryption-data', [WebAuthnAuthController::class, 'getEncryptionData'])
        ->name('vault.encryption-data');
    
    // PIN reset (requires authentication)
    Route::post('auth/webauthn/reset-pin', [WebAuthnAuthController::class, 'resetPin'])
        ->name('webauthn.reset-pin');
});

