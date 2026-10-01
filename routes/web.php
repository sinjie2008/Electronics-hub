<?php

use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\SessionLoginController;
use Illuminate\Support\Facades\Route;
use Modules\System\Http\Controllers\BackupDownloadController;

Route::view('/', 'home')->name('home');
Route::middleware('guest')->group(function (): void {
    Route::view('/login', 'auth.login')->name('login');
    Route::view('/forgot-password', 'auth.forgot-password')->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'send'])->middleware('throttle:6,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:6,1')->name('password.update');
    Route::post('/login', [SessionLoginController::class, 'store'])->middleware('throttle:10,1');
});
Route::post('/logout', [SessionLoginController::class, 'destroy'])->middleware('auth')->name('logout');
Route::get('/admin/system/backups/download/{disk}/{filename}', BackupDownloadController::class)
    ->middleware(['auth', 'active.user', 'verified'])
    ->where(['disk' => '[A-Za-z0-9_-]+', 'filename' => '[A-Za-z0-9_.-]+'])
    ->name('system.backups.download');
