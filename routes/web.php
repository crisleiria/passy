<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('passwords', [\App\Http\Controllers\PasswordManagerController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('passwords');

Route::post('passwords', [\App\Http\Controllers\PasswordManagerController::class, 'store'])
    ->middleware(['auth', 'verified'])
    ->name('passwords.store');

Route::delete('passwords/{password}', [\App\Http\Controllers\PasswordManagerController::class, 'destroy'])
    ->middleware(['auth', 'verified'])
    ->name('passwords.destroy');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
