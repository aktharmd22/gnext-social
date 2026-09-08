<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One workspace per execution, resolved once. Middleware sets it for
        // web requests; jobs set it explicitly before touching scoped models.
        $this->app->singleton(WorkspaceContext::class);
    }

    public function boot(): void
    {
        /*
         * Fail loudly in development rather than silently in production.
         *
         * The calendar month view must render from one query plus eager loads,
         * so lazy loading is an error, not a performance note: an N+1 across 31
         * day cells is exactly the regression this guard exists to catch.
         */
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Dates are stored and compared in UTC everywhere. Only the view layer
        // converts, and it always states the zone.
        Date::use(\Illuminate\Support\Carbon::class);

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        $this->shareShellCounts();
    }

    /**
     * The two standing numbers the shell always shows: how many posts are
     * waiting on an admin, and how many tokens are about to lapse.
     *
     * Both are persistent signals rather than toasts -- an expiring token stops
     * the product working, and a post stuck in approval past its scheduled time
     * has already failed quietly.
     */
    private function shareShellCounts(): void
    {
        // Note the name: the shell is a Blade *component* layout, so it
        // resolves as components.layouts.app, not layouts.app.
        View::composer('components.layouts.app', function ($view): void {
            $user = auth()->user();

            if ($user === null) {
                return;
            }

            $view->with(
                'pendingApprovals',
                \App\Models\Post::query()->awaitingApproval()->count() ?: null
            );

            $view->with(
                'expiringTokens',
                $user->isAdmin()
                    ? \App\Models\SocialAccount::query()
                        ->active()
                        ->whereNotNull('token_expires_at')
                        ->where('token_expires_at', '<=', now()->addDays(max(config('gnext.tokens.warn_days'))))
                        ->count()
                    : 0
            );
        });
    }
}
