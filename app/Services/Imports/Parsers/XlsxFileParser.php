<?php

namespace App\Services\Imports\Parsers;

use App\Models\SourceFile;
use App\Services\Imports\Contracts\SourceFileHeaderReader;
use App\Services\Imports\Contracts\SourceFileParser;
use App\Services\Imports\Exceptions\ImportException;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Row;

class XlsxFileParser implements SourceFileHeaderReader, SourceFileParser
{
    /**
     * Read and validate the first non-empty row from the first worksheet.
     *
     * @return list<string>
     */
    public function headers(SourceFile $sourceFile): array
    {
        $temporaryPath = $this->copyToTemporaryPath($sourceFile);
        $spreadsheet = null;

        try {
            $reader = IOFactory::createReaderForFile($temporaryPath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($temporaryPath);

            foreach ($spreadsheet->getSheet(0)->getRowIterator() as $row) {
                $values = $this->rowValues($row);

                if (! $this->isEmptyRow($values)) {
                    return $this->validatedHeaders($values);
                }
            }

            throw new ImportException('The XLSX file does not contain a header row.');
        } catch (ImportException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new ImportException('The XLSX file could not be read.');
        } finally {
            if ($spreadsheet !== null) {
                $spreadsheet->disconnectWorksheets();
            }

            @unlink($temporaryPath);
        }
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function rows(SourceFile $sourceFile): iterable
    {
        $temporaryPath = $this->copyToTemporaryPath($sourceFile);

        try {
            $reader = IOFactory::createReaderForFile($temporaryPath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($temporaryPath);
            $worksheet = $spreadsheet->getSheet(0);
            $rows = $worksheet->getRowIterator();
            $headers = null;

            /** @var Row $row */
            foreach ($rows as $row) {
                $values = $this->rowValues($row);

                if ($this->isEmptyRow($values)) {
                    continue;
                }

                if ($headers === null) {
                    $headers = $this->validatedHeaders($values);

                    continue;
                }

                $values = array_pad($values, count($headers), null);
                $values = array_slice($values, 0, count($headers));

                yield array_combine($headers, $values);
            }

            if ($headers === null) {
                throw new ImportException('The XLSX file does not contain a header row.');
            }

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        } catch (ImportException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new ImportException('The XLSX file could not be read.');
        } finally {
            @unlink($temporaryPath);
        }
    }

    /**
     * Copy the private disk object to a temporary XLSX-named file for PhpSpreadsheet.
     */
    private function copyToTemporaryPath(SourceFile $sourceFile): string
    {
        $stream = Storage::disk($sourceFile->disk)->readStream($sourceFile->path);

        if (! is_resource($stream)) {
            throw new ImportException('The XLSX file could not be opened.');
        }

        $temporaryBase = tempnam(sys_get_temp_dir(), 'dataset-import-');

        if ($temporaryBase === false) {
            fclose($stream);

            throw new ImportException('A temporary file could not be created for the XLSX import.');
        }

        $temporaryPath = $temporaryBase.'.xlsx';

        if (! rename($temporaryBase, $temporaryPath)) {
            fclose($stream);
            @unlink($temporaryBase);

            throw new ImportException('A temporary file could not be created for the XLSX import.');
        }

        $target = fopen($temporaryPath, 'wb');

        if (! is_resource($target)) {
            fclose($stream);
            @unlink($temporaryPath);

            throw new ImportException('A temporary file could not be created for the XLSX import.');
        }

        try {
            if (stream_copy_to_stream($stream, $target) === false) {
                throw new ImportException('The XLSX file could not be copied for reading.');
            }
        } finally {
            fclose($stream);
            fclose($target);
        }

        return $temporaryPath;
    }

    /**
     * @return list<mixed>
     */
    private function rowValues(Row $row): array
    {
        $values = [];
        $cells = $row->getCellIterator();
        $cells->setIterateOnlyExistingCells(false);

        foreach ($cells as $cell) {
            $values[] = $cell->getValue();
        }

        return $values;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function validatedHeaders(array $values): array
    {
        $headers = array_map(
            fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
            $values,
        );

        if (in_array('', $headers, true) || count($headers) !== count(array_unique($headers))) {
            throw new ImportException('The XLSX header row must contain unique, non-empty names.');
        }

        return $headers;
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
