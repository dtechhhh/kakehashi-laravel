<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', fn () => view('auth.login'))->name('login.form');
    Route::get('/two-factor/challenge', fn () => view('auth.two-factor-challenge'))->name('two-factor.challenge');
    Route::get('/lockout', fn () => view('auth.lockout'))->name('lockout');
});

Route::post('/language', function () {
    $locale = request()->input('locale');

    if (! in_array($locale, ['id', 'ja'], true)) {
        abort(404);
    }

    session(['locale' => $locale]);

    return back();
})->name('language.switch');

Route::middleware('auth')->group(function (): void {
    Route::get('/home', fn () => view('dashboard'))->name('home');

    Route::get('/password/forced', fn () => view('auth.password-forced'))->name('password.forced');
    Route::get('/two-factor/enroll', fn () => view('auth.two-factor-enroll'))->name('two-factor.enroll');

    Route::get('/admin/users', fn () => view('admin.users'))->middleware('can:viewAny,App\Models\User')->name('admin.users');
    Route::get('/audit', fn () => view('admin.audit-log'))->middleware('can:viewAny,App\Models\User')->name('audit.index');

    Route::get('/lookup', fn () => view('lookup.index'))->middleware('can:lookup.manage')->name('lookup.index');
    Route::get('/lookup/requests', fn () => view('lookup.requests'))->middleware('can:lookup.request.decide')->name('lookup.requests');
    Route::get('/companies', fn () => view('lookup.companies'))->middleware('can:company.manage')->name('company.index');

    Route::get('/candidates', fn () => view('candidates.index'))->middleware('can:candidate.view')->name('candidate.index');
    Route::get('/candidates/create', fn () => view('candidates.form'))->middleware('can:candidate.create')->name('candidate.create');
    Route::get('/candidates/{candidate}', fn (string $candidate) => view('candidates.show', ['candidate' => $candidate]))
        ->middleware('can:candidate.view')
        ->whereNumber('candidate')
        ->name('candidate.show');
    Route::get('/candidates/{candidate}/edit', fn (string $candidate) => view('candidates.form', ['candidate' => $candidate]))
        ->middleware('can:candidate.create')
        ->whereNumber('candidate')
        ->name('candidate.edit');
    Route::get('/candidates/{candidate}/revision', fn (string $candidate) => view('candidates.revision', ['candidate' => $candidate]))
        ->middleware('can:candidate.view')
        ->whereNumber('candidate')
        ->name('candidate.revision');
    Route::get('/candidates/review', fn () => view('candidates.review'))->middleware('can:candidate.review')->name('candidate.review');

    Route::get('/jobs/review', fn () => view('jobs.review'))->middleware('can:jobs.review')->name('jobs.review');
    Route::get('/jobs/create', fn () => view('jobs.form'))->middleware('can:jobs.execute')->name('jobs.create');
    Route::get('/jobs', fn () => view('jobs.index'))->middleware('can:jobs.view')->name('jobs.index');
    Route::get('/jobs/{interviewContainer}/edit', fn (string $interviewContainer) => view('jobs.form', ['interviewContainer' => $interviewContainer]))
        ->middleware('can:jobs.execute')
        ->whereNumber('interviewContainer')
        ->name('jobs.edit');
    Route::get('/jobs/{interviewContainer}', fn (string $interviewContainer) => view('jobs.show', ['interviewContainer' => $interviewContainer]))
        ->middleware('can:jobs.view')
        ->whereNumber('interviewContainer')
        ->name('jobs.show');

    Route::get('/placements/review', fn () => view('placement.review'))->middleware('can:placement.review')->name('placements.review');
    Route::get('/placements/create', fn () => view('placement.form'))->middleware('can:placement.execute')->name('placements.create');
    Route::get('/placements', fn () => view('placement.index'))->middleware('can:placement.view')->name('placements.index');
    Route::get('/placements/{placement}/edit', fn (string $placement) => view('placement.form', ['placement' => $placement]))
        ->middleware('can:placement.execute')
        ->whereNumber('placement')
        ->name('placements.edit');
    Route::get('/placements/{placement}', fn (string $placement) => view('placement.show', ['placement' => $placement]))
        ->middleware('can:placement.view')
        ->whereNumber('placement')
        ->name('placements.show');

});
