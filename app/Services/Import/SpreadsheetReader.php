<?php

declare(strict_types=1);

namespace App\Services\Import;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/**
 * Reads a CSV or XLSX into a header row and data rows.
 *
 * Deliberately dumb: it does not interpret anything. Every decision about what
 * a column means belongs to the operator in step 2, because guessing wrongly
 * about a date format silently schedules a month of content on the wrong days.
 */
class SpreadsheetReader
{
    /**
     * @return array{headers: list<string>, rows: list<array<int, string>>}
     */
    public function read(string $absolutePath): array
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('That file could not be read.');
        }

        $sheets = Excel::toArray(new class implements ToArray
        {
            public function array(array $array): array
            {
                return $array;
            }
        }, $absolutePath);

        $rows = $sheets[0] ?? [];

        if ($rows === []) {
            throw new RuntimeException('That file appears to be empty.');
        }

        // The first non-empty row is the header. Exported sheets often carry a
        // blank line or a title above the real headings.
        $headerIndex = null;

        foreach ($rows as $index => $row) {
            if ($this->hasContent($row)) {
                $headerIndex = $index;

                break;
            }
        }

        if ($headerIndex === null) {
            throw new RuntimeException('That file has no readable rows.');
        }

        $headers = array_map(
            fn ($value) => trim((string) $value),
            $rows[$headerIndex]
        );

        $data = [];

        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            if (! $this->hasContent($row)) {
                continue;
            }

            $data[] = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $row);
        }

        return ['headers' => $headers, 'rows' => $data];
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function hasContent(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }
}
