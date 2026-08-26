<?php

namespace App\Services\Imports\Contracts;

use App\Models\SourceFile;

interface SourceFileHeaderReader
{
    /**
     * Read the first non-empty header row using the same rules as importing.
     *
     * @return list<string>
     */
    public function headers(SourceFile $sourceFile): array;
}
