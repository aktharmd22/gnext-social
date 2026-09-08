<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\Post;
use App\Services\Media\SpecValidator;
use App\Services\Media\UrlResolver;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The dry run. Decides, per row, whether it can become a post.
 *
 * Nothing is created here. The whole point of step 3 is that an operator sees
 * every verdict before a single row is committed, because importing a month of
 * content onto the wrong dates is far harder to undo than to prevent.
 */
class RowValidator
{
    public function __construct(
        private readonly UrlResolver $urls = new UrlResolver,
        private readonly SpecValidator $specs = new SpecValidator,
    ) {}

    /**
     * @param  list<array<int, mixed>>  $rows
     * @param  array<string, int|null>  $mapping
     * @param  array{date_format: string, default_time: string, timezone: string, account_ids: list<int>}  $options
     * @return list<RowVerdict>
     */
    public function validateAll(array $rows, array $mapping, array $options): array
    {
        $verdicts = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            $verdict = $this->validate($row, $mapping, $options, $index + 2, $seen);

            if ($verdict->scheduledAt !== null) {
                // Track slots so a collision inside the same file is caught,
                // not just against what is already in the database.
                $seen[] = $verdict->scheduledAt->toDateTimeString();
            }

            $verdicts[] = $verdict;
        }

        return $verdicts;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int|null>  $mapping
     * @param  array{date_format: string, default_time: string, timezone: string, account_ids: list<int>}  $options
     * @param  list<string>  $seen
     */
    public function validate(array $row, array $mapping, array $options, int $lineNumber, array $seen = []): RowVerdict
    {
        $raw = $this->extract($row, $mapping);

        $problems = [];
        $warnings = [];
        $notes = [];

        // ---------------------------------------------------------- date
        $scheduledAt = null;

        if (trim((string) $raw['date']) === '') {
            $problems[] = 'No date.';
        } else {
            $scheduledAt = $this->parseDate(
                $raw['date'],
                $raw['time'] ?: $options['default_time'],
                $options['date_format'],
                $options['timezone'],
            );

            if ($scheduledAt === null) {
                $problems[] = sprintf(
                    'Could not read "%s" as a date in %s format.',
                    $raw['date'],
                    $options['date_format']
                );
            } elseif ($scheduledAt->isPast()) {
                // A warning, not an error: back-filling a historical calendar
                // is a legitimate thing to want.
                $warnings[] = 'This date is in the past, so it will import as a draft rather than being scheduled.';
            }
        }

        // ------------------------------------------------------- caption
        $caption = trim((string) $raw['caption']);

        if ($caption === '') {
            $problems[] = 'No caption.';
        } else {
            foreach ($this->specs->validateCaption($caption, Platform::Instagram) as $verdict) {
                if ($verdict->isError()) {
                    $problems[] = $verdict->message;
                }
            }
        }

        // --------------------------------------------------------- media
        $mediaUrl = trim((string) $raw['media_url']);

        if ($mediaUrl !== '') {
            if ($reason = $this->urls->rejectionReason($mediaUrl)) {
                $problems[] = $reason;
            } elseif ($this->urls->isDriveUrl($mediaUrl)) {
                $notes[] = 'Drive link will be downloaded after import.';
            }
        } else {
            $warnings[] = 'No media. Instagram cannot publish without it.';
        }

        // ---------------------------------------------------------- type
        $type = $this->parseType($raw['type']);

        if (trim((string) $raw['type']) !== '' && $type === null) {
            $warnings[] = sprintf('Unknown type "%s", treated as a feed post.', $raw['type']);
        }

        // ----------------------------------------------------- collision
        if ($scheduledAt !== null) {
            if (in_array($scheduledAt->toDateTimeString(), $seen, true)) {
                $warnings[] = 'Another row in this file uses the same slot.';
            }

            if ($this->slotTaken($scheduledAt, $options['account_ids'])) {
                $warnings[] = 'A post is already scheduled in this slot.';
            }
        }

        // Notes deliberately do not affect the status.
        $status = match (true) {
            $problems !== [] => RowVerdict::ERROR,
            $warnings !== [] => RowVerdict::WARNING,
            default => RowVerdict::READY,
        };

        return new RowVerdict(
            lineNumber: $lineNumber,
            status: $status,
            raw: $raw,
            problems: $problems,
            warnings: $warnings,
            notes: $notes,
            scheduledAt: $scheduledAt,
            caption: $caption !== '' ? $caption : null,
            mediaUrl: $mediaUrl !== '' ? $mediaUrl : null,
            type: ($type ?? PostType::Post)->value,
            title: trim((string) $raw['title']) ?: null,
        );
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int|null>  $mapping
     * @return array<string, string>
     */
    private function extract(array $row, array $mapping): array
    {
        $values = [];

        foreach (array_keys(ColumnMapper::FIELDS) as $field) {
            $index = $mapping[$field] ?? null;

            $values[$field] = $index !== null && array_key_exists($index, $row)
                ? (is_string($row[$index]) ? trim($row[$index]) : (string) $row[$index])
                : '';
        }

        return $values;
    }

    /**
     * Parse a date in the format the operator explicitly chose.
     *
     * Never guessed. 03-04-2026 is the third of April or the fourth of March
     * depending on who made the sheet, and getting it wrong silently publishes
     * a month of content on the wrong days.
     */
    public function parseDate(string $date, string $time, string $format, string $timezone): ?Carbon
    {
        $date = trim($date);
        $time = trim($time) !== '' ? trim($time) : '09:00';

        // Excel stores dates as a serial number of days since 1899-12-30.
        if (is_numeric($date) && (float) $date > 1000) {
            try {
                $parsed = Carbon::create(1899, 12, 30, 0, 0, 0, $timezone)
                    ->addDays((int) (float) $date);

                return $this->applyTime($parsed, $time);
            } catch (Throwable) {
                return null;
            }
        }

        $normalised = str_replace(['/', '.'], '-', $date);
        $normalised = preg_replace('/\s+/', ' ', $normalised) ?? $normalised;

        // Strip a trailing time already present in the cell; the time column
        // (or the default) is authoritative.
        $normalised = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?$/', '', $normalised) ?? $normalised;

        $patterns = $format === 'MM-DD-YYYY'
            ? ['m-d-Y', 'm-d-y', 'Y-m-d']
            : ['d-m-Y', 'd-m-y', 'Y-m-d'];

        // Textual months are unambiguous whichever way round the sheet is.
        $patterns[] = 'j F Y';
        $patterns[] = 'j M Y';
        $patterns[] = 'F j, Y';
        $patterns[] = 'M j, Y';

        foreach ($patterns as $pattern) {
            try {
                $parsed = Carbon::createFromFormat($pattern, $normalised, $timezone);

                if ($parsed !== false && $parsed->format($pattern) === $normalised) {
                    return $this->applyTime($parsed, $time);
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function applyTime(Carbon $date, string $time): ?Carbon
    {
        if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches) !== 1) {
            return $date->setTime(9, 0);
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        // "7:00 PM" in a spreadsheet is common.
        if (stripos($time, 'pm') !== false && $hour < 12) {
            $hour += 12;
        }

        if (stripos($time, 'am') !== false && $hour === 12) {
            $hour = 0;
        }

        if ($hour > 23 || $minute > 59) {
            return $date->setTime(9, 0);
        }

        return $date->setTime($hour, $minute);
    }

    private function parseType(string $value): ?PostType
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return PostType::Post;
        }

        return match (true) {
            str_contains($value, 'reel'), str_contains($value, 'video') => PostType::Reel,
            str_contains($value, 'carousel'), str_contains($value, 'album') => PostType::Carousel,
            str_contains($value, 'story'), str_contains($value, 'stories') => PostType::Story,
            str_contains($value, 'post'), str_contains($value, 'image'),
            str_contains($value, 'photo'), str_contains($value, 'static'),
            str_contains($value, 'graphic') => PostType::Post,
            default => null,
        };
    }

    /**
     * @param  list<int>  $accountIds
     */
    private function slotTaken(Carbon $scheduledAt, array $accountIds): bool
    {
        if ($accountIds === []) {
            return false;
        }

        return Post::query()
            ->where('scheduled_at', $scheduledAt->copy()->utc())
            ->whereHas('targets', fn ($q) => $q->whereIn('social_account_id', $accountIds))
            ->exists();
    }
}
