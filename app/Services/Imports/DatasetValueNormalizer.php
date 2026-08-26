<?php

namespace App\Services\Imports;

use App\Models\DatasetField;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DatasetValueNormalizer
{
    /**
     * Normalize one source value according to the configured DatasetField.
     */
    public function normalize(DatasetField $field, mixed $value): mixed
    {
        $value = $this->applyNormalizer($field->normalizer, $value);

        return match ($field->data_type) {
            'string', 'url' => $this->normalizeString($field, $value),
            'integer' => $this->normalizeInteger($field, $value),
            'decimal' => $this->normalizeDecimal($field, $value),
            'boolean' => $this->normalizeBoolean($field, $value),
            'date' => $this->normalizeDate($field, $value),
            'datetime' => $this->normalizeDatetime($field, $value),
            default => throw new InvalidArgumentException("Unsupported DatasetField type [{$field->data_type}]."),
        };
    }

    private function applyNormalizer(?string $normalizer, mixed $value): mixed
    {
        if ($normalizer === null || $normalizer === '') {
            return $value;
        }

        return match ($normalizer) {
            'lowercase' => is_scalar($value)
                ? Str::lower((string) $value)
                : throw new InvalidArgumentException('The lowercase normalizer requires a scalar value.'),
            'percentage' => $this->normalizePercentage($value),
            'currency' => $this->normalizeCurrency($value),
            'gb' => $this->normalizeGigabytes($value),
            default => throw new InvalidArgumentException("Unsupported normalizer [{$normalizer}]."),
        };
    }

    private function normalizeString(DatasetField $field, mixed $value): string
    {
        if (! is_scalar($value)) {
            throw new InvalidArgumentException("Field [{$field->key}] must contain a scalar value.");
        }

        $normalized = (string) $value;

        if ($field->data_type === 'url' && filter_var($normalized, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException("Field [{$field->key}] must contain a valid URL.");
        }

        return $normalized;
    }

    private function normalizeInteger(DatasetField $field, mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new InvalidArgumentException("Field [{$field->key}] must contain an integer.");
    }

    private function normalizeDecimal(DatasetField $field, mixed $value): float
    {
        if ((is_int($value) || is_float($value) || is_string($value)) && is_numeric($value)) {
            $normalized = (float) $value;

            if (is_finite($normalized)) {
                return $normalized;
            }
        }

        throw new InvalidArgumentException("Field [{$field->key}] must contain a decimal number.");
    }

    private function normalizeBoolean(DatasetField $field, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === '1' || $value === 'true' || $value === 'yes' || $value === 'on') {
            return true;
        }

        if ($value === 0 || $value === '0' || $value === 'false' || $value === 'no' || $value === 'off') {
            return false;
        }

        throw new InvalidArgumentException("Field [{$field->key}] must contain a boolean value.");
    }

    private function normalizeDate(DatasetField $field, mixed $value): string
    {
        if (! is_scalar($value)) {
            throw new InvalidArgumentException("Field [{$field->key}] must contain a valid date.");
        }

        try {
            return CarbonImmutable::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            throw new InvalidArgumentException("Field [{$field->key}] must contain a valid date.");
        }
    }

    private function normalizeDatetime(DatasetField $field, mixed $value): string
    {
        if (! is_scalar($value)) {
            throw new InvalidArgumentException("Field [{$field->key}] must contain a valid datetime.");
        }

        try {
            return CarbonImmutable::parse((string) $value)->toISOString();
        } catch (\Throwable) {
            throw new InvalidArgumentException("Field [{$field->key}] must contain a valid datetime.");
        }
    }

    private function normalizePercentage(mixed $value): float
    {
        if (! is_scalar($value)) {
            throw new InvalidArgumentException('The percentage normalizer requires a scalar value.');
        }

        $normalized = trim(str_replace('%', '', (string) $value));

        if (! is_numeric($normalized)) {
            throw new InvalidArgumentException('The percentage normalizer requires a numeric value.');
        }

        return (float) $normalized;
    }

    private function normalizeCurrency(mixed $value): float
    {
        if (! is_scalar($value)) {
            throw new InvalidArgumentException('The currency normalizer requires a scalar value.');
        }

        $normalized = preg_replace('/[^0-9.\-]/', '', (string) $value);

        if (! is_string($normalized) || ! is_numeric($normalized)) {
            throw new InvalidArgumentException('The currency normalizer requires a numeric value.');
        }

        return (float) $normalized;
    }

    private function normalizeGigabytes(mixed $value): float
    {
        if (! is_scalar($value)) {
            throw new InvalidArgumentException('The gb normalizer requires a scalar value.');
        }

        if (preg_match('/^\s*(-?(?:\d+\.?\d*|\.\d+))\s*(kb|mb|gb|tb)?\s*$/i', (string) $value, $matches) !== 1) {
            throw new InvalidArgumentException('The gb normalizer requires a numeric storage value.');
        }

        $amount = (float) $matches[1];
        $unit = Str::lower($matches[2] ?? 'gb');

        return match ($unit) {
            'kb' => $amount / (1024 * 1024),
            'mb' => $amount / 1024,
            'tb' => $amount * 1024,
            default => $amount,
        };
    }
}
