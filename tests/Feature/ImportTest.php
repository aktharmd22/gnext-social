<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostSource;
use App\Enums\PostStatus;
use App\Livewire\ImportWizard;
use App\Models\ImportBatch;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Import\ColumnMapper;
use App\Services\Import\RowValidator;
use App\Services\Import\RowVerdict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Importing the original spreadsheet, unmodified.
 *
 * The definition of done is explicit: it must correctly flag every unusable row
 * and import the rest.
 */
class ImportTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $user;

    private SocialAccount $facebook;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create(['timezone' => 'Asia/Dubai']);
        $this->user = User::factory()->admin()->create([
            'workspace_id' => $this->workspace->id,
            'timezone' => 'Asia/Dubai',
        ]);

        $this->facebook = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    }

    /**
     * Shaped exactly like the sheet the team keeps today.
     */
    private function sourceSheet(): string
    {
        $future = now('Asia/Dubai')->addMonth();

        return implode("\n", [
            'Date,Day,Type,Status,Graphic Link,Content',
            sprintf('%s,Monday,Static Post,Ready,https://drive.google.com/file/d/1A2b3C4d5E6f7G8h/view?usp=drive_link,"Back-to-school range is in store now across every branch."',
                $future->copy()->day(3)->format('d-m-Y')),
            sprintf('%s,Wednesday,Reel,Ready,https://drive.google.com/file/d/9Z8y7X6w5V4u3T2s/view,"Watch how we fit a tyre in under twenty minutes."',
                $future->copy()->day(5)->format('d-m-Y')),
            // No date: unusable.
            ',Friday,Static Post,Ready,https://drive.google.com/file/d/aaaaaaaaaaaa/view,"A post with no date at all."',
            // No caption: unusable.
            sprintf('%s,Saturday,Static Post,Draft,https://drive.google.com/file/d/bbbbbbbbbbbb/view,',
                $future->copy()->day(8)->format('d-m-Y')),
            // Unreadable date: unusable.
            sprintf('not a date,Sunday,Static Post,Ready,,"A post whose date cannot be read."'),
            // A Drive folder rather than a file: unusable.
            sprintf('%s,Monday,Static Post,Ready,https://drive.google.com/drive/folders/xyz,"A row pointing at a folder."',
                $future->copy()->day(10)->format('d-m-Y')),
            // No media: importable, with a warning.
            sprintf('%s,Tuesday,Static Post,Ready,,"A perfectly good caption with no artwork attached yet."',
                $future->copy()->day(11)->format('d-m-Y')),
        ])."\n";
    }

    private function upload(string $csv = null): \Livewire\Features\SupportTesting\Testable
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->createWithContent('calendar.csv', $csv ?? $this->sourceSheet());

        return Livewire::actingAs($this->user)
            ->test(ImportWizard::class)
            ->set('file', $file)
            ->call('uploadFile');
    }

    // ================================================================ step 1

    public function test_uploading_reads_the_headers_and_moves_to_mapping(): void
    {
        $this->upload()
            ->assertHasNoErrors()
            ->assertSet('step', 2)
            ->assertSet('headers', ['Date', 'Day', 'Type', 'Status', 'Graphic Link', 'Content']);

        $this->assertSame(1, ImportBatch::withoutGlobalScopes()->count());
    }

    public function test_a_file_that_is_not_a_spreadsheet_is_refused(): void
    {
        Storage::fake('local');

        Livewire::actingAs($this->user)
            ->test(ImportWizard::class)
            ->set('file', UploadedFile::fake()->image('photo.jpg'))
            ->call('uploadFile')
            ->assertHasErrors('file');
    }

    // ================================================================ step 2

    /**
     * The source sheet's own headings must map themselves.
     */
    public function test_the_original_sheet_headings_are_recognised(): void
    {
        $mapping = app(ColumnMapper::class)->suggest(
            ['Date', 'Day', 'Type', 'Status', 'Graphic Link', 'Content']
        );

        $this->assertSame(0, $mapping['date']);
        $this->assertSame(2, $mapping['type']);
        $this->assertSame(3, $mapping['status']);
        $this->assertSame(4, $mapping['media_url'], 'Graphic Link should feed the media field.');
        $this->assertSame(5, $mapping['caption'], 'Content should feed the caption.');
    }

    public function test_it_does_not_confuse_day_with_date(): void
    {
        $mapping = app(ColumnMapper::class)->suggest(['Day', 'Date', 'Content']);

        $this->assertSame(1, $mapping['date'], '"Date" should win over "Day".');
    }

    public function test_mapping_requires_a_date_and_a_caption(): void
    {
        $this->upload()
            ->set('mapping.date', null)
            ->call('confirmMapping')
            ->assertHasErrors('mapping')
            ->assertSet('step', 2);
    }

    public function test_mapping_requires_at_least_one_account(): void
    {
        $this->upload()
            ->set('accountIds', [])
            ->call('confirmMapping')
            ->assertHasErrors('accountIds');
    }

    /**
     * 03-04-2026 is the third of April or the fourth of March depending on who
     * made the sheet. It is asked, never guessed.
     */
    public function test_the_date_format_choice_actually_changes_the_date(): void
    {
        $validator = app(RowValidator::class);

        $dayFirst = $validator->parseDate('03-04-2026', '09:00', 'DD-MM-YYYY', 'Asia/Dubai');
        $monthFirst = $validator->parseDate('03-04-2026', '09:00', 'MM-DD-YYYY', 'Asia/Dubai');

        $this->assertSame('2026-04-03', $dayFirst?->toDateString());
        $this->assertSame('2026-03-04', $monthFirst?->toDateString());
    }

    public function test_it_reads_a_time_from_the_sheet_including_am_pm(): void
    {
        $validator = app(RowValidator::class);

        $this->assertSame('19:30', $validator
            ->parseDate('03-04-2026', '7:30 PM', 'DD-MM-YYYY', 'Asia/Dubai')?->format('H:i'));

        $this->assertSame('08:15', $validator
            ->parseDate('03-04-2026', '08:15', 'DD-MM-YYYY', 'Asia/Dubai')?->format('H:i'));
    }

    /**
     * Excel hands over a serial number, not a date string.
     */
    public function test_it_understands_an_excel_serial_date(): void
    {
        $parsed = app(RowValidator::class)
            ->parseDate('46000', '09:00', 'DD-MM-YYYY', 'Asia/Dubai');

        $this->assertNotNull($parsed);
        $this->assertSame('2025-12-09', $parsed->toDateString());
    }

    // ================================================================ step 3

    public function test_the_dry_run_flags_every_unusable_row(): void
    {
        $component = $this->upload()->call('confirmMapping')->assertSet('step', 3);

        $summary = $component->get('summary');

        $this->assertSame(7, $summary['total']);

        // Four rows cannot be imported: no date, no caption, unreadable date,
        // and a Drive folder rather than a file.
        $this->assertSame(4, $summary['error']);

        // Nothing was created. That is the whole point of a dry run.
        $this->assertSame(0, Post::withoutGlobalScopes()->count());
    }

    public function test_each_rejection_says_why_in_plain_language(): void
    {
        $verdicts = collect($this->upload()->call('confirmMapping')->get('verdicts'));

        $reasons = $verdicts->where('status', 'error')->pluck('reason')->implode(' | ');

        $this->assertStringContainsString('No date', $reasons);
        $this->assertStringContainsString('No caption', $reasons);
        $this->assertStringContainsString('Could not read', $reasons);
        $this->assertStringContainsString('folder', $reasons);
    }

    public function test_a_row_with_no_media_imports_with_a_warning(): void
    {
        $verdicts = collect($this->upload()->call('confirmMapping')->get('verdicts'));

        $warned = $verdicts->firstWhere('status', 'warning');

        $this->assertNotNull($warned);
        $this->assertStringContainsString('Instagram cannot publish without it', $warned['reason']);
    }

    public function test_a_past_date_is_a_warning_not_a_rejection(): void
    {
        $csv = "Date,Content\n"
            .now('Asia/Dubai')->subMonth()->format('d-m-Y').',"Back-filling a historical calendar entry here."'."\n";

        $verdicts = collect($this->upload($csv)->call('confirmMapping')->get('verdicts'));

        $this->assertSame('warning', $verdicts->first()['status']);
        $this->assertStringContainsString('past', $verdicts->first()['reason']);
    }

    // ================================================================ step 4

    public function test_committing_creates_only_the_passing_rows(): void
    {
        Queue::fake();

        $component = $this->upload()->call('confirmMapping')->call('commit');

        $component->assertSet('step', 4);

        // Three importable rows out of seven.
        $this->assertSame(3, Post::withoutGlobalScopes()->count());

        $batch = ImportBatch::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(7, $batch->rows_total);
        $this->assertSame(3, $batch->rows_imported);
        $this->assertSame(4, $batch->rows_failed);
    }

    public function test_imported_posts_are_attributed_and_targeted(): void
    {
        Queue::fake();

        $this->upload()->call('confirmMapping')->call('commit');

        $post = Post::withoutGlobalScopes()->with('targets')->first();

        $this->assertSame(PostSource::Import, $post->source);
        $this->assertSame($this->user->id, $post->created_by);
        $this->assertSame(1, $post->targets->count());
        $this->assertNotNull($post->import_batch_id);
    }

    public function test_drive_links_are_queued_for_fetching_rather_than_downloaded_inline(): void
    {
        Queue::fake();

        $this->upload()->call('confirmMapping')->call('commit');

        // Two of the importable rows carry a Drive link.
        $this->assertSame(2, PostMedia::query()->count());

        Queue::assertPushed(\App\Jobs\FetchMediaJob::class, 2);
    }

    public function test_a_downloadable_error_report_is_written_in_the_original_shape(): void
    {
        Queue::fake();

        $this->upload()->call('confirmMapping')->call('commit');

        $batch = ImportBatch::withoutGlobalScopes()->firstOrFail();

        $this->assertNotNull($batch->error_report_path);
        Storage::disk('local')->assertExists($batch->error_report_path);

        $csv = Storage::disk('local')->get($batch->error_report_path);

        // Same columns as went in, plus the reason.
        $this->assertStringContainsString('Why it was not imported', $csv);
        $this->assertStringContainsString('No caption', $csv);
    }

    // ================================================================== undo

    public function test_undo_removes_what_the_import_created(): void
    {
        Queue::fake();

        $component = $this->upload()->call('confirmMapping')->call('commit');

        $this->assertSame(3, Post::withoutGlobalScopes()->count());

        $component->call('undo');

        $this->assertSame(0, Post::withoutGlobalScopes()->whereNull('deleted_at')->count());
    }

    /**
     * A published post is history. An undo never deletes it.
     */
    public function test_undo_leaves_anything_already_published_alone(): void
    {
        Queue::fake();

        $component = $this->upload()->call('confirmMapping')->call('commit');

        $published = Post::withoutGlobalScopes()->first();
        $published->forceFill([
            'status' => PostStatus::Published,
            'published_at' => now(),
        ])->save();

        $component->call('undo');

        $this->assertNotNull($published->fresh(), 'A published post must survive an undo.');
        $this->assertSame(1, Post::withoutGlobalScopes()->whereNull('deleted_at')->count());
    }

    // =============================================================== scoping

    public function test_the_verdict_object_reports_importability(): void
    {
        $ready = new RowVerdict(2, RowVerdict::READY, []);
        $warning = new RowVerdict(3, RowVerdict::WARNING, []);
        $error = new RowVerdict(4, RowVerdict::ERROR, []);

        $this->assertTrue($ready->isImportable());
        $this->assertTrue($warning->isImportable(), 'A warning is still imported.');
        $this->assertFalse($error->isImportable());
    }
}
