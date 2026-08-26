<?php

namespace App\Services\Imports;

use Illuminate\Support\Str;

class SourcePathResolver
{
    /**
     * Resolve a dot path against an array or object source row.
     *
     * @param  array<string, mixed>  $row
     */
    public function get(array $row, string $path): mixed
    {
        $normalizedPath = Str::startsWith($path, '$.') ? Str::after($path, '$.') : $path;

        if ($normalizedPath === 'root') {
            return $row;
        }

        if (array_key_exists($normalizedPath, $row)) {
            return $row[$normalizedPath];
        }

        if ($normalizedPath === '') {
            return $row;
        }

        $value = $row;

        foreach (explode('.', $normalizedPath) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];

                continue;
            }

            if (is_object($value)) {
                $objectValues = get_object_vars($value);

                if (array_key_exists($segment, $objectValues)) {
                    $value = $objectValues[$segment];

                    continue;
                }
            }

            return null;
        }

        return $value;
    }
}
