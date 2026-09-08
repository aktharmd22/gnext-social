<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use App\Models\SocialAccount;
use App\Services\ActivityLogger;
use App\Services\Import\ColumnMapper;
use App\Services\Import\ImportCommitter;
use App\Services\Import\RowValidator;
use App\Services\Import\RowVerdict;
use App\Services\Import\SpreadsheetReader;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Upload → map → dry run → commit.
 *
 * A genuine sequence, which is why it is the one place in the product with a
 * stepper: each step depends on a decision made in the previous one, and step 3
 * exists so nothing is written until a human has seen every verdict.
 */
class ImportWizard extends Component
{
    use WithFileUploads;

    public int $step = 1;

    public $file;

    public ?int $batchId = null;

    /** @var list<string> */
    public array $headers = [];

    /** @var list<array<int, mixed>> */
    public array $rows = [];

    /** @var array<string, int|null> */
    public array $mapping = [];

    /**
     * Never inferred. 03-04-2026 is the third of April or the fourth of March
     * depending on who made the sheet.
     */
    public string $dateFormat = 'DD-MM-YYYY';

    public string $defaultTime = '09:00';

    /** @var array<int, int> */
    public array $accountIds = [];

    /** @var list<array<string, mixed>> */
    public array $verdicts = [];

    public string $verdictFilter = 'all';

    public ?array $summary = null;

    public function mount(): void
    {
        Gate::authorize('import-posts');

        $this->accountIds = SocialAccount::query()->active()->pluck('id')->all();
    }

    // =====================================================================
    // Step 1 — upload
    // =====================================================================

    public function uploadFile(SpreadsheetReader $reader): void
    {
        $this->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:20480'],
        ], [
            'file.mimes' => 'Upload a CSV or an Excel file.',
            'file.max' => 'That file is over 20MB. Split it, or export just the months you need.',
        ]);

        $stored = $this->file->store('imports/'.auth()->user()->workspace_id, 'local');

        try {
            $parsed = $reader->read(Storage::disk('local')->path($stored));
        } catch (Throwable $exception) {
            $this->addError('file', $exception->getMessage());

            return;
        }

        if ($parsed['rows'] === []) {
            $this->addError('file', 'That file has headers but no rows.');

            return;
        }

        $batch = ImportBatch::create([
            'workspace_id' => auth()->user()->workspace_id,
            'user_id' => auth()->id(),
            'filename' => $this->file->getClientOriginalName(),
            'stored_path' => $stored,
            'rows_total' => count($parsed['rows']),
            'status' => ImportBatchStatus::Previewing,
        ]);

        $this->batchId = $batch->id;
        $this->headers = $parsed['headers'];
        $this->rows = $parsed['rows'];
        $this->mapping = app(ColumnMapper::class)->suggest($parsed['headers']);
        $this->step = 2;
    }

    // =====================================================================
    // Step 2 — map columns
    // =====================================================================

    public function confirmMapping(RowValidator $validator): void
    {
        $missing = app(ColumnMapper::class)->missingRequired($this->mapping);

        if ($missing !== []) {
            $this->addError('mapping', 'Map a column for: '.implode(', ', $missing).'.');

            return;
        }

        if ($this->accountIds === []) {
            $this->addError('accountIds', 'Choose at least one account for these posts.');

            return;
        }

        $verdicts = $validator->validateAll($this->rows, $this->mapping, [
            'date_format' => $this->dateFormat,
            'default_time' => $this->defaultTime,
            'timezone' => auth()->user()->displayTimezone(),
            'account_ids' => $this->accountIds,
        ]);

        // Flattened for the Livewire payload; RowVerdict objects are rebuilt on
        // commit from the same inputs.
        $this->verdicts = array_map(fn (RowVerdict $v) => [
            'line' => $v->lineNumber,
            'status' => $v->status,
            'date' => $v->scheduledAt?->format('D j M Y, H:i'),
            'caption' => \Illuminate\Support\Str::limit((string) $v->caption, 70),
            'type' => $v->type,
            'media' => $v->mediaUrl,
            'reason' => $v->reason(),
        ], $verdicts);

        $this->summary = [
            'ready' => count(array_filter($verdicts, fn ($v) => $v->status === RowVerdict::READY)),
            'warning' => count(array_filter($verdicts, fn ($v) => $v->status === RowVerdict::WARNING)),
            'error' => count(array_filter($verdicts, fn ($v) => $v->status === RowVerdict::ERROR)),
            'total' => count($verdicts),
        ];

        ImportBatch::query()->whereKey($this->batchId)->update([
            'mapping' => $this->mapping,
        ]);

        $this->step = 3;
    }

    public function backToMapping(): void
    {
        $this->step = 2;
        $this->verdicts = [];
        $this->summary = null;
    }

    // =====================================================================
    // Step 3 → 4 — commit
    // =====================================================================

    public function commit(RowValidator $validator, ImportCommitter $committer, ActivityLogger $log): void
    {
        Gate::authorize('import-posts');

        $batch = ImportBatch::query()->findOrFail($this->batchId);

        $verdicts = $validator->validateAll($this->rows, $this->mapping, [
            'date_format' => $this->dateFormat,
            'default_time' => $this->defaultTime,
            'timezone' => auth()->user()->displayTimezone(),
            'account_ids' => $this->accountIds,
        ]);

        $committer->commit($batch, $verdicts, $this->accountIds, (int) auth()->id());

        $log->log('import.completed', $batch->refresh(), [
            'filename' => $batch->filename,
            'imported' => $batch->rows_imported,
            'failed' => $batch->rows_failed,
        ]);

        $this->step = 4;
        $this->dispatch('posts:changed');
        $this->dispatch('toast', message: $batch->rows_imported.' '.str('post')->plural($batch->rows_imported).' imported.');
    }

    public function undo(ImportCommitter $committer, ActivityLogger $log): void
    {
        Gate::authorize('import-posts');

        $batch = ImportBatch::query()->findOrFail($this->batchId);

        $removed = $committer->undo($batch);

        $log->log('import.undone', $batch, ['posts_removed' => $removed]);

        $this->dispatch('posts:changed');
        $this->dispatch('toast', message: $removed.' '.str('post')->plural($removed).' removed.');

        $this->startOver();
    }

    public function startOver(): void
    {
        $this->reset(['step', 'file', 'batchId', 'headers', 'rows', 'mapping', 'verdicts', 'summary']);
        $this->step = 1;
        $this->accountIds = SocialAccount::query()->active()->pluck('id')->all();
    }

    public function downloadErrors()
    {
        $batch = ImportBatch::query()->findOrFail($this->batchId);

        abort_if($batch->error_report_path === null, 404);

        return Storage::disk('local')->download(
            $batch->error_report_path,
            'import-errors-'.$batch->id.'.csv'
        );
    }

    public function render()
    {
        return view('livewire.import-wizard', [
            'fields' => ColumnMapper::FIELDS,
            'accounts' => SocialAccount::query()->active()->orderBy('name')->get(),
            'batch' => $this->batchId !== null ? ImportBatch::query()->find($this->batchId) : null,
            'visibleVerdicts' => $this->verdictFilter === 'all'
                ? $this->verdicts
                : array_values(array_filter($this->verdicts, fn ($v) => $v['status'] === $this->verdictFilter)),
        ]);
    }
}
