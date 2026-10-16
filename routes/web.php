<?php

use App\Http\Controllers\LandingPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LandingPageController::class, 'index'])->name('home');

Route::prefix('download')->name('download.')->group(function () {
    Route::get('/android', [LandingPageController::class, 'downloadAndroid'])->name('android');
    Route::get('/windows', [LandingPageController::class, 'downloadWindows'])->name('windows');
    Route::get('/status', [LandingPageController::class, 'status'])->name('status');
});
