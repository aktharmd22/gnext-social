<?php

declare(strict_types=1);

namespace App\Services\Meta;

use App\Models\AppCredential;
use App\Models\SocialAccount;
use Illuminate\Support\Collection;

/**
 * Re-validates stored tokens against Meta, daily.
 *
 * Long-lived Page tokens expire. Not "might" -- will. Without this, the failure
 * mode is silent: posts simply stop going out, and nobody notices until someone
 * checks the page.
 */
class TokenHealthService
{
    /**
     * Check one account, recording what Meta said.
     *
     * @return array{valid: bool, expires_at: ?\Illuminate\Support\Carbon, message: ?string}
     */
    public function check(SocialAccount $account): array
    {
        $credential = AppCredential::withoutGlobalScopes()
            ->where('workspace_id', $account->workspace_id)
            ->first();

        if ($credential === null || ! $credential->isUsable()) {
            return $this->record($account, false, null, 'No Meta app is configured for this workspace.');
        }

        if (blank($account->access_token)) {
            return $this->record($account, false, null, 'This account has no stored token. Reconnect it.');
        }

        $result = (new MetaOAuthService($credential))->debugToken((string) $account->access_token);

        if (! $result['valid']) {
            return $this->record(
                $account,
                false,
                null,
                $result['message'] ?? 'Meta reports this token is no longer valid. Reconnect the page in Settings.'
            );
        }

        return $this->record($account, true, $result['expires_at'], null);
    }

    /**
     * @return array{valid: bool, expires_at: ?\Illuminate\Support\Carbon, message: ?string}
     */
    private function record(SocialAccount $account, bool $valid, $expiresAt, ?string $message): array
    {
        $account->forceFill([
            'last_checked_at' => now(),
            'last_error' => $message,
            // Only overwrite a known expiry; never clear one Meta did not
            // mention, or a Page token would look immortal.
            'token_expires_at' => $expiresAt ?? $account->token_expires_at,
        ])->save();

        return ['valid' => $valid, 'expires_at' => $expiresAt, 'message' => $message];
    }

    /**
     * Accounts an admin should be told about now.
     *
     * @return Collection<int, SocialAccount>
     */
    public function needingAttention(?int $workspaceId = null): Collection
    {
        $warnDays = max((array) config('gnext.tokens.warn_days', [14, 7]));

        return SocialAccount::withoutGlobalScopes()
            ->when($workspaceId !== null, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->where('is_active', true)
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now()->addDays($warnDays))
            ->orderBy('token_expires_at')
            ->get();
    }

    /**
     * Whether today is one of the configured warning days for this account, so
     * that a 14-day warning is not repeated every day for a fortnight.
     */
    public function isWarningDay(SocialAccount $account): bool
    {
        $days = $account->tokenExpiresInDays();

        if ($days === null) {
            return false;
        }

        // Expired, or on one of the thresholds exactly.
        return $days <= 0 || in_array($days, (array) config('gnext.tokens.warn_days', [14, 7]), true);
    }
}
