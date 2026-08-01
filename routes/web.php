<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/home', fn () => view('dashboard'))->name('home');

    Route::post('/language', function () {
        $locale = request()->input('locale');

        if (! in_array($locale, ['id', 'ja'], true)) {
            abort(404);
        }

        session(['locale' => $locale]);

        return back();
    })->name('language.switch');
});
