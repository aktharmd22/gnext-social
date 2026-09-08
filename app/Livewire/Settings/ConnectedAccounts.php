<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Enums\TargetStatus;
use App\Models\AppCredential;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Settings > Connected accounts.
 *
 * Three jobs: start the Facebook connect flow, choose which discovered Pages
 * this workspace publishes to, and show the health of the tokens behind them.
 *
 * Token health is shown, token values never are.
 */
class ConnectedAccounts extends Component
{
    private const PAGES_KEY = 'meta.oauth.pages';

    private const EXPIRY_KEY = 'meta.oauth.expires_at';

    /** @var array<int, string> page ids ticked in the picker */
    public array $selected = [];

    public ?int $confirmingDisconnect = null;

    public function mount(): void
    {
        Gate::authorize('manage-accounts');

        // Everything discovered is pre-ticked: the common case is connecting
        // all of them, and unticking is easier than hunting.
        $this->selected = collect($this->pendingPages())
            ->pluck('page_id')
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pendingPages(): array
    {
        return (array) session(self::PAGES_KEY, []);
    }

    public function connectSelected(ActivityLogger $log): void
    {
        Gate::authorize('manage-accounts');

        $credential = $this->credential();

        if ($credential === null) {
            $this->dispatch('toast', message: 'No Meta app is configured.');

            return;
        }

        $expiresAt = session(self::EXPIRY_KEY);
        $expiresAt = $expiresAt !== null ? Carbon::parse($expiresAt) : null;

        $oauth = new \App\Services\Meta\MetaOAuthService($credential);

        $connected = 0;

        foreach ($this->pendingPages() as $page) {
            if (! in_array($page['page_id'], $this->selected, true)) {
                continue;
            }

            $accounts = $oauth->connectPage($page, $expiresAt);
            $connected += count($accounts);

            foreach ($accounts as $account) {
                $log->log('account.connected', $account, [
                    'platform' => $account->platform->value,
                    'name' => $account->name,
                ]);
            }
        }

        // Page access tokens must not linger in the session past this step.
        session()->forget([self::PAGES_KEY, self::EXPIRY_KEY]);

        $this->selected = [];

        $this->dispatch('toast', message: $connected === 0
            ? 'Nothing was selected, so nothing was connected.'
            : $connected.' '.str('destination')->plural($connected).' connected.');
    }

    public function discardPending(): void
    {
        session()->forget([self::PAGES_KEY, self::EXPIRY_KEY]);
        $this->selected = [];
    }

    public function confirmDisconnect(int $accountId): void
    {
        $this->confirmingDisconnect = $accountId;
    }

    public function cancelDisconnect(): void
    {
        $this->confirmingDisconnect = null;
    }

    /**
     * Disconnecting deactivates and forgets the credential. It does not delete
     * the account.
     *
     * Two reasons. Anything queued against this destination is marked skipped
     * with a reason, so a post that stops going out is visible rather than
     * mysterious. And post_targets cascade from social_accounts, so deleting
     * the row would take published permalinks, captured insights and the whole
     * publish log with it -- history that is still true, and still wanted.
     */
    public function disconnect(int $accountId, ActivityLogger $log): void
    {
        Gate::authorize('manage-accounts');

        $account = SocialAccount::query()->findOrFail($accountId);

        $skipped = PostTarget::query()
            ->where('social_account_id', $account->id)
            ->whereIn('status', [TargetStatus::Queued->value, TargetStatus::Publishing->value])
            ->update([
                'status' => TargetStatus::Skipped->value,
                'error_code' => 'account_disconnected',
                'error_message' => sprintf(
                    'Skipped because %s was disconnected on %s.',
                    $account->displayName(),
                    now()->timezone(auth()->user()->displayTimezone())->format('j M Y')
                ),
            ]);

        $account->forceFill([
            'is_active' => false,
            // The credential is revoked locally. Nothing can publish with it.
            'access_token' => null,
            'token_expires_at' => null,
            'last_checked_at' => now(),
            'last_error' => 'Disconnected by '.auth()->user()->name.'.',
        ])->save();

        $log->log('account.disconnected', $account, [
            'platform' => $account->platform->value,
            'name' => $account->name,
            'queued_posts_skipped' => $skipped,
        ]);

        $this->confirmingDisconnect = null;

        $this->dispatch('toast', message: $skipped > 0
            ? 'Disconnected. '.$skipped.' queued '.str('post')->plural($skipped).' marked skipped.'
            : 'Disconnected.');
    }

    private function credential(): ?AppCredential
    {
        return AppCredential::query()
            ->where('workspace_id', auth()->user()->workspace_id)
            ->first();
    }

    public function render()
    {
        $credential = $this->credential();

        return view('livewire.settings.connected-accounts', [
            'accounts' => SocialAccount::query()->orderBy('name')->orderBy('platform')->get(),
            'pending' => $this->pendingPages(),
            'hasCredential' => $credential !== null && $credential->isUsable(),
        ]);
    }
}
