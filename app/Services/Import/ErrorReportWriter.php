<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\ImportBatch;
use Illuminate\Support\Facades\Storage;

/**
 * Writes the rejected rows back out in the shape they arrived in, plus a reason
 * column.
 *
 * Same shape on purpose: the operator fixes the file and re-uploads it, rather
 * than hunting through the original for the eleven rows that failed.
 */
class ErrorReportWriter
{
    /**
     * @param  list<RowVerdict>  $rejected
     * @return string the stored path
     */
    public function write(ImportBatch $batch, array $rejected): string
    {
        $fields = array_keys(ColumnMapper::FIELDS);

        $headers = array_map(
            fn (string $field) => ColumnMapper::FIELDS[$field]['label'],
            $fields
        );

        $headers[] = 'Why it was not imported';

        $rows = [$headers];

        foreach ($rejected as $verdict) {
            $row = [];

            foreach ($fields as $field) {
                $row[] = (string) ($verdict->raw[$field] ?? '');
            }

            $row[] = $verdict->reason();
            $rows[] = $row;
        }

        $path = sprintf(
            'imports/%d/errors-%s.csv',
            $batch->workspace_id,
            $batch->id
        );

        Storage::disk('local')->put($path, $this->toCsv($rows));

        return $path;
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        // A BOM, so Excel opens Arabic captions as UTF-8 rather than mojibake.
        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
