<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\LoginController;
use Modules\Auth\Http\Controllers\LogoutController;
use Modules\Auth\Http\Controllers\PasswordController;
use Modules\Auth\Http\Controllers\StepUpController;
use Modules\Auth\Http\Controllers\TwoFactorChallengeController;
use Modules\Auth\Http\Controllers\TwoFactorEnrollmentController;

Route::post('/login', [LoginController::class, 'store'])
    ->middleware('guest')
    ->name('login');

Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
    ->middleware('guest')
    ->name('two-factor.login.store');

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LogoutController::class, 'destroy'])
        ->name('logout');

    Route::post('/user/password', [PasswordController::class, 'update'])
        ->name('password.update');

    Route::post('/user/two-factor-authentication', [TwoFactorEnrollmentController::class, 'enable'])
        ->name('two-factor.enable');

    Route::post('/user/confirmed-two-factor-authentication', [TwoFactorEnrollmentController::class, 'confirm'])
        ->name('two-factor.confirm');

    Route::delete('/user/two-factor-authentication', [TwoFactorEnrollmentController::class, 'disable'])
        ->name('two-factor.disable');

    Route::get('/user/two-factor-qr-code', [TwoFactorEnrollmentController::class, 'qrCode'])
        ->name('two-factor.qr-code');

    Route::get('/user/two-factor-secret-key', [TwoFactorEnrollmentController::class, 'secretKey'])
        ->name('two-factor.secret-key');

    Route::get('/user/two-factor-recovery-codes', [TwoFactorEnrollmentController::class, 'recoveryCodes'])
        ->name('two-factor.recovery-codes');

    Route::post('/user/two-factor-recovery-codes', [TwoFactorEnrollmentController::class, 'regenerateRecoveryCodes'])
        ->name('two-factor.regenerate-recovery-codes');

    Route::post('/user/step-up', [StepUpController::class, 'store'])
        ->name('step-up.store');

    Route::get('/auth/session', function () {
        return response()->json([
            'authenticated' => true,
            'user_id' => auth()->id(),
        ]);
    })->name('auth.session');
});
