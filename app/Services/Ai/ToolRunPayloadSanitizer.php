<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

final class ToolRunPayloadSanitizer
{
    private const MAX_DEPTH = 5;

    private const MAX_ITEMS = 100;

    private const MAX_STRING_LENGTH = 2000;

    /**
     * @param  array<string|int, mixed>  $payload
     * @return array<string|int, mixed>
     */
    public function sanitize(array $payload): array
    {
        $sanitized = $this->sanitizeValue($payload, 0);

        return is_array($sanitized) ? $sanitized : [];
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return '[omitted]';
        }

        if (is_array($value)) {
            $sanitized = [];

            foreach (array_slice($value, 0, self::MAX_ITEMS, true) as $key => $item) {
                if (is_string($key) && preg_match('/(?:authorization|credential|secret|token|password|api[_-]?key|headers)/i', $key) === 1) {
                    continue;
                }

                $sanitized[$key] = $this->sanitizeValue($item, $depth + 1);
            }

            return $sanitized;
        }

        if (is_string($value)) {
            return Str::limit($value, self::MAX_STRING_LENGTH, '');
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        return '[omitted]';
    }
}
