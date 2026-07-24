<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\LoginController;
use Modules\Auth\Http\Controllers\LogoutController;
use Modules\Auth\Http\Controllers\PasswordController;

Route::post('/login', [LoginController::class, 'store'])
    ->middleware('guest')
    ->name('login');

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LogoutController::class, 'destroy'])
        ->name('logout');

    Route::post('/user/password', [PasswordController::class, 'update'])
        ->name('password.update');

    Route::get('/auth/session', function () {
        return response()->json([
            'authenticated' => true,
            'user_id' => auth()->id(),
        ]);
    })->name('auth.session');
});
