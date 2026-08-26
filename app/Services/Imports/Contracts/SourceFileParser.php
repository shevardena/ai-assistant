<?php

namespace App\Services\Imports\Contracts;

use App\Models\SourceFile;

interface SourceFileParser
{
    /**
     * Yield source rows as associative arrays keyed by source field name.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function rows(SourceFile $sourceFile): iterable;
}
