<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('passwords', function () {
    return Inertia::render('Passwords');
})->middleware(['auth', 'verified'])->name('passwords');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
