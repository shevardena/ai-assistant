<?php

namespace App\Services\Imports\Discovery;

use App\Models\SourceFile;
use App\Services\Imports\Contracts\SourceFileHeaderReader;
use App\Services\Imports\Contracts\SourceFileParser;
use App\Services\Imports\Discovery\Data\DiscoveredSourceField;
use App\Services\Imports\Exceptions\ImportException;
use App\Services\Imports\Parsers\CsvFileParser;
use App\Services\Imports\Parsers\JsonFileParser;
use App\Services\Imports\Parsers\XlsxFileParser;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Str;

class SourceFieldDiscoveryService
{
    public const SAMPLE_ROW_LIMIT = 50;

    private const SAMPLE_VALUE_LIMIT = 5;

    public function __construct(
        private readonly CsvFileParser $csvFileParser,
        private readonly JsonFileParser $jsonFileParser,
        private readonly XlsxFileParser $xlsxFileParser,
    ) {}

    /**
     * Inspect a bounded sample using the same parser selected by file import.
     *
     * @return list<DiscoveredSourceField>
     */
    public function discover(SourceFile $sourceFile, ?string $primaryKeyPath = null): array
    {
        $parser = $this->parser($sourceFile);
        $paths = [];

        if ($parser instanceof SourceFileHeaderReader) {
            foreach ($parser->headers($sourceFile) as $header) {
                $paths[$header] = [];
            }
        }

        $rowsRead = 0;

        foreach ($parser->rows($sourceFile) as $row) {
            $rowsRead++;

            foreach ($this->flatten($row) as $path => $value) {
                $paths[$path] ??= [];

                if ($this->isMeaningful($value) && count($paths[$path]) < self::SAMPLE_VALUE_LIMIT) {
                    $paths[$path][] = $this->sampleValue($value);
                }
            }

            if ($rowsRead >= self::SAMPLE_ROW_LIMIT) {
                break;
            }
        }

        if ($paths === []) {
            throw new ImportException('No fields could be discovered from this source.');
        }

        $fields = [];

        foreach ($paths as $path => $samples) {
            $fields[] = $this->field($path, $samples, $primaryKeyPath);
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function flatten(array $row, string $prefix = ''): array
    {
        $fields = [];

        foreach ($row as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                if (! array_is_list($value)) {
                    $fields += $this->flatten($value, $path);
                }

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $fields[$path] = $value;
            }
        }

        return $fields;
    }

    /**
     * @param  list<string>  $samples
     */
    private function field(string $path, array $samples, ?string $primaryKeyPath): DiscoveredSourceField
    {
        $type = $this->inferType($path, $samples);
        $basename = Str::lower(Str::afterLast($path, '.'));

        return new DiscoveredSourceField(
            sourcePath: $path,
            suggestedInternalKey: $this->suggestedKey($path),
            suggestedLabel: $this->suggestedLabel($path),
            suggestedType: $type,
            sampleValues: array_values(array_unique($samples)),
            confidence: $samples === [] ? 'low' : ($type === 'string' ? 'medium' : 'high'),
            isSearchable: in_array($basename, ['name', 'title', 'brand', 'category'], true),
            isFilterable: in_array($basename, ['brand', 'category', 'price', 'in_stock', 'stock'], true)
                || $type === 'boolean',
            isSortable: in_array($basename, ['price', 'rating', 'score'], true)
                || in_array($type, ['integer', 'decimal', 'date', 'datetime'], true),
            isDisplayable: true,
            isPrimaryKey: $this->normalizePath($path) === $this->normalizePath($primaryKeyPath),
        );
    }

    /**
     * Infer only types accepted by DatasetValueNormalizer.
     *
     * @param  list<string>  $samples
     */
    private function inferType(string $path, array $samples): string
    {
        $meaningful = array_values(array_filter($samples, fn (string $value): bool => trim($value) !== ''));

        if ($this->isIdentifierPath($path) || $meaningful === []) {
            return 'string';
        }

        $lower = array_map(fn (string $value): string => Str::lower(trim($value)), $meaningful);

        if ($this->all($lower, fn (string $value): bool => in_array($value, [
            'true', 'false', '1', '0', 'yes', 'no', 'on', 'off',
        ], true))) {
            return 'boolean';
        }

        if ($this->all($meaningful, fn (string $value): bool => preg_match('/^-?\d+$/', trim($value)) === 1)) {
            return 'integer';
        }

        if ($this->all($meaningful, fn (string $value): bool => is_numeric($value))) {
            return 'decimal';
        }

        if ($this->all($meaningful, fn (string $value): bool => $this->isHttpUrl($value))) {
            return 'url';
        }

        if ($this->all($meaningful, fn (string $value): bool => $this->isDate($value))) {
            return 'date';
        }

        if ($this->all($meaningful, fn (string $value): bool => $this->isDatetime($value))) {
            return 'datetime';
        }

        return 'string';
    }

    private function isIdentifierPath(string $path): bool
    {
        $basename = Str::lower(Str::afterLast($path, '.'));

        return $basename === 'id' || Str::endsWith($basename, '_id');
    }

    private function isHttpUrl(string $value): bool
    {
        $scheme = parse_url($value, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true)
            && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    private function isDate(string $value): bool
    {
        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
            $errors = CarbonImmutable::getLastErrors();
        } catch (\Throwable) {
            return false;
        }

        return $date !== null
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    private function isDatetime(string $value): bool
    {
        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value) !== 1) {
            return false;
        }

        try {
            CarbonImmutable::parse($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function suggestedKey(string $path): string
    {
        $key = Str::lower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $this->normalizePath($path)));
        $key = trim($key, '_');

        return preg_match('/^\d/', $key) === 1 ? 'field_'.$key : $key;
    }

    private function suggestedLabel(string $path): string
    {
        $segments = explode('.', $this->normalizePath($path));
        $segments = count($segments) > 1 ? array_slice($segments, -2) : $segments;
        $words = preg_split('/[_\-\s]+/', implode(' ', $segments), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $acronyms = ['api', 'gb', 'id', 'ram', 'sku', 'url', 'xlsx'];

        $words = array_map(
            fn (string $word): string => in_array(Str::lower($word), $acronyms, true)
                ? Str::upper($word)
                : Str::lower($word),
            $words,
        );

        return ucfirst(implode(' ', $words));
    }

    private function normalizePath(?string $path): string
    {
        return is_string($path) ? Str::replaceStart('$.', '', $path) : '';
    }

    private function isMeaningful(mixed $value): bool
    {
        return $value !== null && (! is_string($value) || trim($value) !== '');
    }

    private function sampleValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param  list<string>  $values
     */
    private function all(array $values, Closure $predicate): bool
    {
        foreach ($values as $value) {
            if (! $predicate($value)) {
                return false;
            }
        }

        return true;
    }

    private function parser(SourceFile $sourceFile): SourceFileParser
    {
        return match ($this->extension($sourceFile)) {
            'csv' => $this->csvFileParser,
            'json' => $this->jsonFileParser,
            'xlsx' => $this->xlsxFileParser,
            default => throw new ImportException('The selected source file format is not supported.'),
        };
    }

    private function extension(SourceFile $sourceFile): string
    {
        $metadataExtension = data_get($sourceFile->metadata, 'extension');

        return Str::lower(is_string($metadataExtension) && $metadataExtension !== ''
            ? $metadataExtension
            : pathinfo($sourceFile->original_name, PATHINFO_EXTENSION));
    }
}
