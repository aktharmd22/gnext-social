<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Notifications\TokenExpiringNotification;
use App\Services\Meta\TokenHealthService;
use App\Services\NotificationDispatcher;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;

/**
 * Daily. Asks Meta whether every stored token is still good, and warns before
 * one lapses rather than after.
 */
class CheckTokenHealth extends Command
{
    protected $signature = 'gnext:check-tokens {--quiet-alerts : Check without sending any notifications}';

    protected $description = 'Re-validate every connected account token and warn about expiries';

    public function handle(
        TokenHealthService $health,
        NotificationDispatcher $dispatcher,
        WorkspaceContext $workspace,
    ): int {
        $checked = 0;
        $problems = 0;

        $workspace->runUnscoped(function () use ($health, $dispatcher, &$checked, &$problems): void {
            $accounts = SocialAccount::withoutGlobalScopes()
                ->with('workspace')
                ->where('is_active', true)
                ->get();

            foreach ($accounts as $account) {
                $result = $health->check($account);
                $checked++;

                if (! $result['valid']) {
                    $problems++;
                    $this->error(sprintf('  %s: %s', $account->displayName(), $result['message']));
                } else {
                    $days = $account->fresh()->tokenExpiresInDays();

                    $this->line(sprintf(
                        '  %s: valid%s',
                        $account->displayName(),
                        $days !== null ? ', expires in '.$days.' days' : ''
                    ));
                }

                $account->refresh();

                // Warn on the configured thresholds only, so a 14-day warning
                // is not repeated every day for a fortnight.
                if ($this->option('quiet-alerts') || $account->workspace === null) {
                    continue;
                }

                if (! $result['valid'] || $health->isWarningDay($account)) {
                    $notification = new TokenExpiringNotification($account);

                    $dispatcher->send(
                        $account->workspace,
                        $notification,
                        $notification->toPlainText()
                    );
                }
            }
        });

        $this->info(sprintf('Checked %d %s, %d with problems.',
            $checked, str('account')->plural($checked), $problems));

        return self::SUCCESS;
    }
}
