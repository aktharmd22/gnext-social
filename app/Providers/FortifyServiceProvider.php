<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        $this->authentication();
        $this->rateLimiting();
        $this->recordSignIns();
    }

    /**
     * Deactivated accounts are refused at the credential check, not after.
     *
     * Doing it here rather than only in middleware means a deactivated user
     * never obtains a session at all, so nothing downstream has to remember to
     * check -- and the message says what actually happened.
     */
    private function authentication(): void
    {
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::withoutGlobalScopes()
                ->where('email', Str::lower((string) $request->input('email')))
                ->first();

            if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            if (! $user->is_active) {
                return null;
            }

            return $user;
        });
    }

    private function rateLimiting(): void
    {
        // Five attempts per minute per email + IP pair.
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(
                Str::lower((string) $request->input('email')).'|'.$request->ip()
            );

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by((string) $request->session()->get('login.id'));
        });

        // The shareable client review link is public by design, so it gets its
        // own limiter rather than inheriting an authenticated one.
        RateLimiter::for('review-link', function (Request $request) {
            return Limit::perMinute((int) config('gnext.review.rate_limit_per_minute'))
                ->by((string) $request->ip());
        });
    }

    private function recordSignIns(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            $user = $event->user;

            if ($user instanceof User) {
                $user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });
    }
}
