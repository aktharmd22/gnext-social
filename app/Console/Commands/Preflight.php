<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MediaStatus;
use App\Enums\Platform;
use App\Models\AppCredential;
use App\Models\PostMedia;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Meta\MetaOAuthService;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Everything that must be true before a post can publish, checked in one go.
 *
 * Written for the moment real credentials arrive: instead of scheduling a post
 * and waiting to see whether it works, this says up front exactly what is
 * missing and what to do about it.
 */
class Preflight extends Command
{
    protected $signature = 'gnext:preflight';

    protected $description = 'Check everything required to publish, and report what is missing';

    private int $problems = 0;

    private int $warnings = 0;

    public function handle(WorkspaceContext $context): int
    {
        $this->newLine();
        $this->line('  <options=bold>GnextSocial preflight</>');
        $this->newLine();

        $context->runUnscoped(function (): void {
            $this->checkEnvironment();
            $this->checkQueue();

            foreach (Workspace::query()->where('is_active', true)->get() as $workspace) {
                $this->newLine();
                $this->line('  <options=bold>'.$workspace->name.'</>');

                $this->checkCredentials($workspace);
                $this->checkAccounts($workspace);
                $this->checkMedia($workspace);
            }
        });

        $this->newLine();

        if ($this->problems > 0) {
            $this->line('  <fg=red;options=bold>'.$this->problems.' '
                .str('problem')->plural($this->problems).' must be fixed before anything will publish.</>');
        } elseif ($this->warnings > 0) {
            $this->line('  <fg=yellow;options=bold>Ready to publish, with '.$this->warnings.' '
                .str('warning')->plural($this->warnings).'.</>');
        } else {
            $this->line('  <fg=green;options=bold>Ready to publish.</>');
        }

        $this->newLine();

        return $this->problems > 0 ? self::FAILURE : self::SUCCESS;
    }

    // =====================================================================

    private function checkEnvironment(): void
    {
        $ffmpeg = $this->binaryWorks((string) config('gnext.media.ffprobe'));

        $this->report(
            $ffmpeg,
            'ffprobe is available',
            'ffprobe was not found. Video duration and dimensions cannot be checked, '
                .'so Reels will be validated only after Meta rejects them.',
            fatal: false,
        );

        $this->report(
            extension_loaded('gd'),
            'GD is loaded (thumbnails, crop and pad)',
            'The gd extension is missing. Thumbnails and auto-fit will not work.',
            fatal: false,
        );

        $disk = (string) config('gnext.media.disk');

        $this->report(
            $disk === 'public',
            'Media disk is publicly reachable ('.$disk.')',
            'Media is stored on the "'.$disk.'" disk. Meta fetches media itself over the '
                .'public internet, so it must be served from somewhere it can reach.',
            fatal: true,
        );

        $base = (string) config('gnext.meta.base_url');

        if ($base !== 'https://graph.facebook.com') {
            $this->report(false, '', 'Graph API is pointed at '.$base.', not Meta. '
                .'Unset GNEXT_GRAPH_BASE_URL before going live.', fatal: false);
        }
    }

    private function checkQueue(): void
    {
        try {
            $pending = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')->count();

            $this->report(true, 'Queue reachable ('.$pending.' pending, '.$failed.' failed)', '', fatal: false);

            if ($failed > 0) {
                $this->report(false, '', $failed.' failed '.str('job')->plural($failed)
                    .' are waiting. Inspect with: php artisan queue:failed', fatal: false);
            }
        } catch (Throwable $exception) {
            $this->report(false, '', 'The queue tables are unreachable: '.$exception->getMessage(), fatal: true);
        }
    }

    private function checkCredentials(Workspace $workspace): void
    {
        $credential = AppCredential::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->first();

        if ($credential === null || ! $credential->isUsable()) {
            $this->report(false, '', 'No Meta app is configured. Add an App ID and secret in Settings.', fatal: true);

            return;
        }

        $this->report(true, 'Meta app configured (Graph '.$credential->graphVersion().')', '', fatal: false);

        try {
            $result = (new MetaOAuthService($credential))
                ->debugToken($credential->meta_app_id.'|'.$credential->meta_app_secret);

            if ($result['valid']) {
                $this->report(true, 'Meta accepted the app credentials', '', fatal: false);

                $missing = array_values(array_diff((array) config('gnext.meta.scopes'), $result['scopes']));

                if ($missing !== [] && $result['scopes'] !== []) {
                    $this->report(false, '', 'Scopes not yet granted: '.implode(', ', $missing)
                        .'. These are granted per connected Page, and some need App Review.', fatal: false);
                }
            } else {
                $this->report(false, '', 'Meta rejected the app credentials: '
                    .($result['message'] ?? 'no reason given'), fatal: true);
            }
        } catch (Throwable $exception) {
            $this->report(false, '', 'Could not reach Meta: '.$exception->getMessage(), fatal: true);
        }
    }

    private function checkAccounts(Workspace $workspace): void
    {
        $accounts = SocialAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->get();

        if ($accounts->isEmpty()) {
            $this->report(false, '', 'No accounts are connected. Connect a Facebook Page in Settings.', fatal: true);

            return;
        }

        foreach ($accounts as $account) {
            if ($account->tokenHasExpired()) {
                $this->report(false, '', $account->displayName().': the token expired on '
                    .$account->token_expires_at->format('j M Y').'. Reconnect it.', fatal: true);

                continue;
            }

            $days = $account->tokenExpiresInDays();

            if ($days !== null && $account->tokenNeedsAttention()) {
                $this->report(false, '', $account->displayName().': the token expires in '
                    .$days.' '.str('day')->plural((int) $days).'. Reconnect it soon.', fatal: false);

                continue;
            }

            $this->report(
                true,
                $account->displayName().' connected'.($days !== null ? ' (token good for '.$days.' days)' : ''),
                '',
                fatal: false,
            );
        }

        // Instagram publishes through the Page it is linked to, so an IG
        // account with no Page beside it cannot work.
        foreach ($accounts->where('platform', Platform::Instagram) as $instagram) {
            $hasPage = $accounts
                ->where('platform', Platform::Facebook)
                ->where('page_id', $instagram->page_id)
                ->isNotEmpty();

            if (! $hasPage) {
                $this->report(false, '', $instagram->displayName()
                    .' has no connected Facebook Page. Instagram publishes through its linked Page, '
                    .'so this account cannot publish on its own.', fatal: true);
            }
        }
    }

    private function checkMedia(Workspace $workspace): void
    {
        $failed = PostMedia::query()
            ->whereHas('post', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->where('status', MediaStatus::Failed->value)
            ->count();

        if ($failed > 0) {
            $this->report(false, '', $failed.' media '.str('file')->plural($failed)
                .' failed to fetch. Meta will have nothing to read for those posts. '
                .'Retry them in the Media library.', fatal: false);

            return;
        }

        $this->report(true, 'All media fetched successfully', '', fatal: false);
    }

    // =====================================================================

    private function report(bool $ok, string $success, string $failure, bool $fatal): void
    {
        if ($ok) {
            if ($success !== '') {
                $this->line('    <fg=green>✓</> '.$success);
            }

            return;
        }

        if ($fatal) {
            $this->problems++;
            $this->line('    <fg=red>✕</> '.$failure);
        } else {
            $this->warnings++;
            $this->line('    <fg=yellow>◐</> '.$failure);
        }
    }

    private function binaryWorks(string $binary): bool
    {
        try {
            return \Illuminate\Support\Facades\Process::timeout(10)
                ->run([$binary, '-version'])
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
