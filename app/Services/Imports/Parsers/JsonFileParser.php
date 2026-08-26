<?php

namespace App\Services\Imports\Parsers;

use App\Models\SourceFile;
use App\Services\Imports\Contracts\SourceFileParser;
use App\Services\Imports\Exceptions\ImportException;
use Illuminate\Support\Facades\Storage;
use JsonException;

class JsonFileParser implements SourceFileParser
{
    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function rows(SourceFile $sourceFile): iterable
    {
        $stream = Storage::disk($sourceFile->disk)->readStream($sourceFile->path);

        if (! is_resource($stream)) {
            throw new ImportException('The JSON file could not be opened.');
        }

        try {
            $contents = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        if (! is_string($contents)) {
            throw new ImportException('The JSON file could not be read.');
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ImportException('The JSON file is malformed.');
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new ImportException('The JSON file must contain a top-level array of records.');
        }

        foreach ($decoded as $row) {
            if (! is_array($row)) {
                throw new ImportException('Every JSON record must be an object.');
            }

            if ($row !== []) {
                yield $row;
            }
        }
    }
}
