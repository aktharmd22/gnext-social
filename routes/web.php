<?php

declare(strict_types=1);

use App\Http\Controllers\ExportController;
use App\Http\Controllers\FacebookOAuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ReviewController;
use App\Livewire\Composer;
use App\Livewire\Dashboard;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Guest
|------------------------------------------------------------------------------
| Fortify runs headless (config/fortify.php: views => false), so it registers
| the POST endpoints and we render every view ourselves. The names below are the
| ones Fortify redirects to, so they must match exactly.
*/

/*
| Health. Public by design so an uptime monitor can reach it, and deliberately
| free of anything identifying: counts only, never account names or captions.
*/
Route::get('/up', HealthController::class)->name('health');

/*
| The shareable client review link.
|
| Signed and expiring, so it needs no account: a client should not have to log
| in to say "yes, publish that". Read-only -- an approval here is a signal, and
| someone on the team still confirms it, or a forwarded link could put content
| live.
*/
Route::middleware('signed')->group(function (): void {
    Route::get('/review/{uuid}', [ReviewController::class, 'show'])->name('review.show');
    Route::post('/review/{uuid}', [ReviewController::class, 'decide'])->name('review.decide');
});

Route::middleware('guest')->group(function (): void {
    Route::view('/login', 'auth.login')->name('login');

    Route::view('/forgot-password', 'auth.forgot-password')->name('password.request');

    Route::get('/reset-password/{token}', fn (string $token) => view('auth.reset-password', [
        'token' => $token,
        'email' => request('email'),
    ]))->name('password.reset');

    Route::view('/two-factor-challenge', 'auth.two-factor-challenge')->name('two-factor.login');
});

/*
|------------------------------------------------------------------------------
| Application
|------------------------------------------------------------------------------
| The dashboard is the home screen: what is going out, what is broken, and
| what the month looks like. The calendar is one click from it.
*/

Route::middleware(['auth'])->group(function (): void {

    Route::get('/', Dashboard::class)->name('dashboard');

    // The calendar is a Livewire component: month, week, list and board views,
    // drag to reschedule, gap markers and the UAE overlay all live in it.
    Route::view('/calendar', 'calendar.index')->name('calendar');

    Route::view('/posts', 'posts.index')->name('posts.index');

    /*
    | The composer is a page, not a modal: a post has a URL, so it can be
    | linked, opened in its own tab from the calendar, and reached with the
    | browser's back button.
    */
    Route::get('/posts/create', Composer::class)->name('posts.create');
    Route::get('/posts/{post}/edit', Composer::class)->name('posts.edit');
    Route::view('/media', 'media.index')->name('media.index');
    Route::view('/insights', 'insights.index')->name('insights');
    Route::view('/import', 'import.index')->name('import.index');
    Route::view('/templates', 'templates.index')->name('templates.index');
    Route::view('/approvals', 'approvals.index')->name('approvals');

    Route::view('/profile', 'profile.index')->name('profile');

    /*
    | Export the current view. Filters arrive in the query string -- the same
    | ones the calendar keeps there -- so "export what I am looking at" needs no
    | state handed between components.
    */
    Route::get('/exports/posts.{format}', [ExportController::class, 'posts'])
        ->whereIn('format', ['xlsx', 'csv'])
        ->name('exports.posts');

    Route::get('/exports/calendar.pdf', [ExportController::class, 'calendar'])
        ->name('exports.calendar');

    /*
    | API configuration: open to both roles by explicit product decision.
    |
    | The secret and every token remain write-only and encrypted regardless of
    | who is looking, so reaching this screen does not mean reading a
    | credential -- it means being able to replace one.
    */
    Route::prefix('settings')->name('settings.')->group(function (): void {
        Route::view('/meta-app', 'settings.meta-app')->name('meta-app');
        Route::view('/accounts', 'settings.accounts')->name('accounts');
    });

    /*
    | The Facebook connect round trip. The callback path is what an operator
    | pastes into the Meta dashboard, so it must stay stable.
    */
    Route::prefix('oauth/facebook')->name('oauth.facebook.')->group(function (): void {
        Route::get('/redirect', [FacebookOAuthController::class, 'redirect'])->name('redirect');
        Route::get('/callback', [FacebookOAuthController::class, 'callback'])->name('callback');
    });

    /*
    | Admin. Guarded at the route as well as by policy: hiding a nav link is
    | not access control.
    */
    Route::middleware('admin')->group(function (): void {
        Route::view('/activity', 'activity.index')->name('activity');

        Route::prefix('settings')->name('settings.')->group(function (): void {
            Route::view('/brand', 'settings.brand')->name('brand');
            Route::view('/notifications', 'settings.notifications')->name('notifications');
            Route::view('/import-defaults', 'settings.import-defaults')->name('import-defaults');
            Route::view('/users', 'settings.users')->name('users');
            Route::view('/events', 'settings.events')->name('events');
        });
    });
});
