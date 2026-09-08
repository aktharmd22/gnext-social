<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MediaStatus;
use App\Enums\TargetStatus;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Is this installation actually able to publish?
 *
 * Deliberately more than "the web server answered". A green tick while every
 * token is expired and the queue is 400 deep would be worse than no check at
 * all, so each of the four things that silently stop publishing gets its own
 * signal.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->database(),
            'queue' => $this->queue(),
            'tokens' => $this->tokens(),
            'media' => $this->media(),
            'publishing' => $this->publishing(),
        ];

        $failing = collect($checks)->contains(fn (array $check) => $check['status'] === 'fail');
        $degraded = collect($checks)->contains(fn (array $check) => $check['status'] === 'warn');

        return response()->json([
            'status' => $failing ? 'fail' : ($degraded ? 'warn' : 'ok'),
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ], $failing ? 503 : 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function database(): array
    {
        try {
            DB::connection()->getPdo();

            return ['status' => 'ok'];
        } catch (Throwable $exception) {
            return ['status' => 'fail', 'detail' => $exception->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function queue(): array
    {
        try {
            $pending = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->count();

            return [
                'status' => $failed > 0 ? 'warn' : ($pending > 500 ? 'warn' : 'ok'),
                'pending' => $pending,
                'failed_last_24h' => $failed,
            ];
        } catch (Throwable $exception) {
            return ['status' => 'fail', 'detail' => $exception->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function tokens(): array
    {
        $active = SocialAccount::withoutGlobalScopes()->where('is_active', true);

        $expired = (clone $active)
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<', now())
            ->count();

        $expiring = (clone $active)
            ->whereNotNull('token_expires_at')
            ->whereBetween('token_expires_at', [now(), now()->addDays(14)])
            ->count();

        return [
            // An expired token means publishing has already stopped.
            'status' => $expired > 0 ? 'fail' : ($expiring > 0 ? 'warn' : 'ok'),
            'connected' => (clone $active)->count(),
            'expired' => $expired,
            'expiring_within_14_days' => $expiring,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function media(): array
    {
        $failed = PostMedia::query()->where('status', MediaStatus::Failed->value)->count();

        // Fetching for over an hour means a worker died mid-download.
        $stale = PostMedia::query()
            ->whereIn('status', [MediaStatus::Pending->value, MediaStatus::Fetching->value])
            ->where('updated_at', '<', now()->subHour())
            ->count();

        return [
            'status' => $stale > 0 ? 'warn' : ($failed > 0 ? 'warn' : 'ok'),
            'failed' => $failed,
            'stuck_fetching' => $stale,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publishing(): array
    {
        $overdue = PostTarget::query()
            ->where('status', TargetStatus::Queued->value)
            ->whereHas('post', fn ($q) => $q->due(now()->subMinutes(15)))
            ->count();

        $stuck = PostTarget::query()
            ->where('status', TargetStatus::Publishing->value)
            ->where('last_attempt_at', '<', now()->subMinutes(30))
            ->count();

        $failed = PostTarget::query()
            ->where('status', TargetStatus::Failed->value)
            ->where('last_attempt_at', '>=', now()->subDay())
            ->count();

        return [
            // Overdue means the scheduler is not running at all.
            'status' => ($overdue > 0 || $stuck > 0) ? 'fail' : ($failed > 0 ? 'warn' : 'ok'),
            'overdue_by_15_minutes' => $overdue,
            'stuck_publishing' => $stuck,
            'failed_last_24h' => $failed,
        ];
    }
}
