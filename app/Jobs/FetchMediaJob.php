<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PostMedia;
use App\Services\Media\MediaIngestor;
use App\Support\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Ingest one attachment off the request cycle.
 *
 * Downloading a 400MB Reel is not something a form submission should wait for,
 * which is why the composer shows a "Fetching" state rather than blocking.
 */
class FetchMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public readonly int $postMediaId) {}

    /**
     * Retry a little way apart: most fetch failures are a slow origin or a
     * transient Drive redirect, not a permanently broken link.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(MediaIngestor $ingestor, WorkspaceContext $workspace): void
    {
        $media = PostMedia::with('post')->find($this->postMediaId);

        if ($media === null) {
            return;
        }

        // A job has no authenticated user, so the workspace has to be
        // established explicitly before anything scoped is touched.
        $workspaceId = $media->post?->workspace_id;

        if ($workspaceId === null) {
            $ingestor->ingest($media);

            return;
        }

        $workspace->runFor($workspaceId, fn () => $ingestor->ingest($media));
    }

    public function failed(\Throwable $exception): void
    {
        $media = PostMedia::find($this->postMediaId);

        $media?->forceFill([
            'status' => \App\Enums\MediaStatus::Failed,
            'error_message' => 'We gave up fetching this after several attempts: '.$exception->getMessage(),
        ])->save();
    }
}
