<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\ImportBatchStatus;
use App\Enums\MediaSourceType;
use App\Enums\MediaStatus;
use App\Enums\PostSource;
use App\Enums\PostStatus;
use App\Jobs\FetchMediaJob;
use App\Models\ImportBatch;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Media\UrlResolver;
use Illuminate\Support\Facades\DB;

/**
 * Writes the rows that passed the dry run.
 *
 * Transactional, and media is queued rather than fetched inline: importing 60
 * rows must not mean waiting on 60 downloads before the page responds.
 */
class ImportCommitter
{
    public function __construct(
        private readonly UrlResolver $urls = new UrlResolver,
        private readonly ErrorReportWriter $errors = new ErrorReportWriter,
    ) {}

    /**
     * @param  list<RowVerdict>  $verdicts
     * @param  list<int>  $accountIds
     */
    public function commit(ImportBatch $batch, array $verdicts, array $accountIds, int $userId): ImportBatch
    {
        $importable = array_values(array_filter($verdicts, fn (RowVerdict $v) => $v->isImportable()));
        $rejected = array_values(array_filter($verdicts, fn (RowVerdict $v) => ! $v->isImportable()));

        $batch->forceFill(['status' => ImportBatchStatus::Importing])->save();

        $mediaIds = [];

        DB::transaction(function () use ($batch, $importable, $accountIds, $userId, &$mediaIds): void {
            foreach ($importable as $verdict) {
                $post = Post::create([
                    'workspace_id' => $batch->workspace_id,
                    'title' => $verdict->title,
                    'caption' => $verdict->caption,
                    'type' => $verdict->type,
                    'append_brand_footer' => true,
                    'scheduled_at' => $verdict->scheduledAt?->copy()->utc(),
                    // A row dated in the past imports as a draft: back-filling
                    // a historical calendar must never publish anything.
                    'status' => $verdict->scheduledAt?->isPast() ? PostStatus::Draft : PostStatus::Scheduled,
                    'created_by' => $userId,
                    'source' => PostSource::Import,
                    'import_batch_id' => $batch->id,
                ]);

                foreach ($accountIds as $accountId) {
                    PostTarget::create([
                        'post_id' => $post->id,
                        'social_account_id' => $accountId,
                    ]);
                }

                if ($verdict->mediaUrl !== null) {
                    $media = PostMedia::create([
                        'post_id' => $post->id,
                        'position' => 0,
                        'source_type' => $this->urls->isDriveUrl($verdict->mediaUrl)
                            ? MediaSourceType::Drive
                            : MediaSourceType::Url,
                        'source_url' => $verdict->mediaUrl,
                        'status' => MediaStatus::Pending,
                    ]);

                    $mediaIds[] = $media->id;
                }
            }
        });

        // Queued outside the transaction: a job that starts before the commit
        // lands would look up a row that does not exist yet.
        foreach ($mediaIds as $mediaId) {
            FetchMediaJob::dispatch($mediaId);
        }

        $reportPath = $rejected !== []
            ? $this->errors->write($batch, $rejected)
            : null;

        $batch->forceFill([
            'status' => ImportBatchStatus::Completed,
            'rows_total' => count($verdicts),
            'rows_imported' => count($importable),
            'rows_failed' => count($rejected),
            'error_report_path' => $reportPath,
        ])->save();

        return $batch->refresh();
    }

    /**
     * Undo removes only what this batch created, and only what has not gone
     * out. A published post is history and is never deleted by an undo.
     *
     * @return int posts removed
     */
    public function undo(ImportBatch $batch): int
    {
        $removed = 0;

        DB::transaction(function () use ($batch, &$removed): void {
            $posts = $batch->undoablePosts()->get();

            foreach ($posts as $post) {
                $post->delete();
                $removed++;
            }
        });

        return $removed;
    }
}
