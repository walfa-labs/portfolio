<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

/*
 * The app-mediated media byte route (§9.7): every public object is streamed
 * here, never linked directly. `{media}` binds the ULID row and `{variant}`
 * is the full variant key including its extension, e.g. `cover_800x450.webp`.
 * The row's own disk is used, so the `s3-private` CV stays unreachable — it
 * has no variants to resolve.
 */
Route::get('/media/{media}/{variant}', [MediaController::class, 'show'])
    ->middleware('throttle:media-download')
    ->name('media.show');
