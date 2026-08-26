<?php

namespace App\Services\Imports\Parsers;

use App\Models\SourceFile;
use App\Services\Imports\Contracts\SourceFileHeaderReader;
use App\Services\Imports\Contracts\SourceFileParser;
use App\Services\Imports\Exceptions\ImportException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CsvFileParser implements SourceFileHeaderReader, SourceFileParser
{
    /**
     * Read and validate the CSV header row.
     *
     * @return list<string>
     */
    public function headers(SourceFile $sourceFile): array
    {
        $stream = Storage::disk($sourceFile->disk)->readStream($sourceFile->path);

        if (! is_resource($stream)) {
            throw new ImportException('The CSV file could not be opened.');
        }

        try {
            $header = fgetcsv($stream, null, ',');

            if (! is_array($header)) {
                throw new ImportException('The CSV file does not contain a header row.');
            }

            $headers = array_map(
                fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                $header,
            );
            $headers[0] = Str::replaceStart("\xEF\xBB\xBF", '', $headers[0]);

            if (in_array('', $headers, true) || count($headers) !== count(array_unique($headers))) {
                throw new ImportException('The CSV header row must contain unique, non-empty names.');
            }

            return $headers;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function rows(SourceFile $sourceFile): iterable
    {
        $headers = $this->headers($sourceFile);
        $stream = Storage::disk($sourceFile->disk)->readStream($sourceFile->path);

        if (! is_resource($stream)) {
            throw new ImportException('The CSV file could not be opened.');
        }

        try {
            fgetcsv($stream, null, ',');

            while (($values = fgetcsv($stream, null, ',')) !== false) {
                $values = array_pad($values, count($headers), null);
                $values = array_slice($values, 0, count($headers));

                if ($this->isEmptyRow($values)) {
                    continue;
                }

                yield array_combine($headers, $values);
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  list<mixed>  $values
     */
    private function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
