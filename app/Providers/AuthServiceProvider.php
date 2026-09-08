<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Post;
use App\Models\User;
use App\Policies\PostPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * The capability matrix, expressed as gates and policies.
 *
 * No permissions package: two roles and roughly a dozen capabilities are
 * clearer as code than as rows in a role_has_permissions table that nobody
 * reads and no test covers.
 */
class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    protected $policies = [
        Post::class => PostPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        /*
         * Admin-only capabilities. Each guards either an irreversible action
         * against a live audience, or a record that must not be editable by the
         * people it describes.
         */
        $adminOnly = [
            'manage-users',

            // Approving your own work defeats the approval step.
            'approve-posts',

            // Publishes to a live audience, immediately.
            'publish-now',

            'view-publish-logs',
            'view-activity-log',
            'manage-calendar-events',
            'manage-notifications',
            'manage-brand',
            'manage-import-defaults',
        ];

        foreach ($adminOnly as $ability) {
            Gate::define($ability, fn (User $user): bool => $user->isAdmin());
        }

        /*
         * API configuration is open to both roles by explicit product decision,
         * overriding the original brief, which reserved it to admins.
         *
         * The boundary that remains is stronger than a role check and applies
         * to everyone: the app secret and every access token are encrypted at
         * rest, hidden from serialisation, and write-only in the UI. No role
         * can read a stored secret back out -- an admin included. Widening who
         * may configure the API therefore does not widen who may read a
         * credential, which is what SecretsAreNeverExposedTest pins down.
         */
        Gate::define('manage-credentials', fn (User $user): bool => true);
        Gate::define('manage-accounts', fn (User $user): bool => true);

        // Token *health* -- expiry, scopes, last check. Never the token itself.
        Gate::define('view-tokens', fn (User $user): bool => true);

        /*
         * Shared capabilities. Both roles manage templates and hashtag sets,
         * and both import; export is filtered per-role at the query level
         * rather than denied outright.
         */
        Gate::define('manage-templates', fn (User $user): bool => true);
        Gate::define('import-posts', fn (User $user): bool => true);
        Gate::define('export-posts', fn (User $user): bool => true);
    }
}
