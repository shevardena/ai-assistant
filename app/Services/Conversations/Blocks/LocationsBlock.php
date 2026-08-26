<?php

namespace App\Services\Conversations\Blocks;

use Illuminate\Support\Str;

final readonly class LocationsBlock implements ConversationBlock
{
    public const MAX_LOCATIONS = 20;

    public const MAX_GENERIC_FIELDS = 8;

    public const MAX_NAME_LENGTH = 255;

    public const MAX_ADDRESS_LENGTH = 500;

    public const MAX_SHORT_TEXT_LENGTH = 255;

    public const MAX_HOURS_LENGTH = 500;

    public const MAX_URL_LENGTH = 2000;

    public const MAX_FIELD_KEY_LENGTH = 100;

    public const MAX_FIELD_LABEL_LENGTH = 150;

    public const MAX_FIELD_STRING_LENGTH = 500;

    /**
     * @param  list<array<string, mixed>>  $locations
     */
    public function __construct(public array $locations) {}

    /**
     * Build a trusted block from the scalar collection already allow-listed by the runtime mapper.
     *
     * @param  array<int|string, mixed>  $mappedLocations
     */
    public static function fromMappedCollection(array $mappedLocations): ?self
    {
        if (! self::isList($mappedLocations)) {
            return null;
        }

        $locations = [];

        foreach ($mappedLocations as $mappedLocation) {
            if (count($locations) >= self::MAX_LOCATIONS) {
                break;
            }

            if (! is_array($mappedLocation)) {
                continue;
            }

            $location = self::normalizeLocation($mappedLocation);

            if ($location !== null) {
                $locations[] = $location;
            }
        }

        return $locations === [] ? null : new self($locations);
    }

    public function type(): string
    {
        return ConversationBlockType::Locations->value;
    }

    /**
     * @return array{type: 'locations', data: array{locations: list<array<string, mixed>>}}
     */
    public function toArray(): array
    {
        return [
            'type' => ConversationBlockType::Locations->value,
            'data' => [
                'locations' => $this->locations,
            ],
        ];
    }

    /**
     * @param  array<int|string, mixed>  $mappedLocation
     * @return array<string, mixed>|null
     */
    private static function normalizeLocation(array $mappedLocation): ?array
    {
        $consumed = [];
        $location = [];

        self::putString($location, 'name', self::stringAlias(
            $mappedLocation,
            ['name', 'store_name', 'location_name'],
            $consumed,
            self::MAX_NAME_LENGTH,
        ));
        self::putString($location, 'address', self::stringAlias(
            $mappedLocation,
            ['address', 'street', 'street_address'],
            $consumed,
            self::MAX_ADDRESS_LENGTH,
        ));
        self::putString($location, 'city', self::stringAlias(
            $mappedLocation,
            ['city'],
            $consumed,
            self::MAX_SHORT_TEXT_LENGTH,
        ));
        self::putString($location, 'region', self::stringAlias(
            $mappedLocation,
            ['region', 'state', 'province'],
            $consumed,
            self::MAX_SHORT_TEXT_LENGTH,
        ));
        self::putString($location, 'postal_code', self::stringAlias(
            $mappedLocation,
            ['postal_code', 'zip', 'zip_code'],
            $consumed,
            self::MAX_SHORT_TEXT_LENGTH,
        ));
        self::putString($location, 'country', self::stringAlias(
            $mappedLocation,
            ['country', 'country_code'],
            $consumed,
            self::MAX_SHORT_TEXT_LENGTH,
        ));

        $latitude = self::coordinate($mappedLocation, ['latitude', 'lat'], $consumed, -90, 90);
        $longitude = self::coordinate($mappedLocation, ['longitude', 'lng', 'lon'], $consumed, -180, 180);

        if ($latitude !== null) {
            $location['latitude'] = $latitude;
        }

        if ($longitude !== null) {
            $location['longitude'] = $longitude;
        }

        [$distance, $distanceUnit] = self::distance($mappedLocation, $consumed);

        if ($distance !== null) {
            $location['distance'] = $distance;

            if ($distanceUnit !== null) {
                $location['distance_unit'] = $distanceUnit;
            }
        }

        self::putString($location, 'phone', self::stringAlias(
            $mappedLocation,
            ['phone', 'phone_number'],
            $consumed,
            self::MAX_SHORT_TEXT_LENGTH,
        ));
        self::putString($location, 'hours', self::stringAlias(
            $mappedLocation,
            ['hours', 'opening_hours'],
            $consumed,
            self::MAX_HOURS_LENGTH,
        ));
        self::putString($location, 'url', self::urlAlias(
            $mappedLocation,
            ['url', 'location_url', 'store_url'],
            $consumed,
        ));

        $fields = self::genericFields($mappedLocation, $consumed);

        if ($fields !== []) {
            $location['fields'] = $fields;
        }

        return $location === [] ? null : $location;
    }

    /**
     * @param  array<int|string, mixed>  $location
     * @param  list<string>  $aliases
     * @param  array<string, bool>  $consumed
     */
    private static function stringAlias(
        array $location,
        array $aliases,
        array &$consumed,
        int $maximum,
    ): ?string {
        foreach ($aliases as $alias) {
            if (! array_key_exists($alias, $location)) {
                continue;
            }

            $consumed[$alias] = true;
            $value = self::safeText($location[$alias], $maximum);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $location
     * @param  list<string>  $aliases
     * @param  array<string, bool>  $consumed
     */
    private static function urlAlias(array $location, array $aliases, array &$consumed): ?string
    {
        foreach ($aliases as $alias) {
            if (! array_key_exists($alias, $location)) {
                continue;
            }

            $consumed[$alias] = true;
            $value = self::safeText($location[$alias], self::MAX_URL_LENGTH);

            if ($value === null) {
                continue;
            }

            $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

            if (in_array($scheme, ['http', 'https'], true)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $location
     * @param  list<string>  $aliases
     * @param  array<string, bool>  $consumed
     */
    private static function coordinate(
        array $location,
        array $aliases,
        array &$consumed,
        float $minimum,
        float $maximum,
    ): int|float|null {
        foreach ($aliases as $alias) {
            if (! array_key_exists($alias, $location)) {
                continue;
            }

            $consumed[$alias] = true;
            $value = $location[$alias];

            if (is_string($value)) {
                $value = trim($value);

                if ($value === '' || ! is_numeric($value)) {
                    continue;
                }

                $value = (float) $value;
            }

            if ((! is_int($value) && ! is_float($value))
                || ! is_finite((float) $value)
                || $value < $minimum
                || $value > $maximum) {
                continue;
            }

            return $value;
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $location
     * @param  array<string, bool>  $consumed
     * @return array{0: int|float|string|null, 1: string|null}
     */
    private static function distance(array $location, array &$consumed): array
    {
        $explicitUnit = self::safeText($location['distance_unit'] ?? null, 32);

        if (array_key_exists('distance_unit', $location)) {
            $consumed['distance_unit'] = true;
        }

        foreach ([
            'distance_km' => 'km',
            'distance_miles' => 'miles',
        ] as $alias => $unit) {
            if (! array_key_exists($alias, $location)) {
                continue;
            }

            $consumed[$alias] = true;
            $distance = self::numericDistance($location[$alias]);

            if ($distance !== null) {
                return [$distance, $unit];
            }
        }

        if (array_key_exists('distance', $location)) {
            $consumed['distance'] = true;
            $distance = self::genericDistance($location['distance']);

            return [$distance, $explicitUnit];
        }

        return [null, null];
    }

    private static function numericDistance(mixed $value): int|float|null
    {
        if (is_string($value)) {
            $value = trim($value);

            if ($value === '' || ! is_numeric($value)) {
                return null;
            }

            $value = (float) $value;
        }

        return (is_int($value) || (is_float($value) && is_finite($value))) && $value >= 0
            ? $value
            : null;
    }

    private static function genericDistance(mixed $value): int|float|string|null
    {
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return $value >= 0 ? $value : null;
        }

        if (is_string($value)) {
            $value = self::safeText($value, self::MAX_SHORT_TEXT_LENGTH);

            return $value === null || (is_numeric($value) && (float) $value < 0) ? null : $value;
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $location
     * @param  array<string, bool>  $consumed
     * @return list<array{key: string, label: string, value: int|float|string|bool}>
     */
    private static function genericFields(array $location, array $consumed): array
    {
        $fields = [];

        foreach ($location as $key => $value) {
            if (! is_string($key)
                || $key === ''
                || isset($consumed[$key])
                || mb_strlen($key) > self::MAX_FIELD_KEY_LENGTH
                || preg_match('/(?:authorization|credential|password|secret|token|email|customer|private|internal)/i', $key) === 1
                || preg_match('/(?:^id$|_id$)/i', $key) === 1) {
                continue;
            }

            $safeValue = self::scalarValue($value);

            if ($safeValue === null) {
                continue;
            }

            $fields[] = [
                'key' => $key,
                'label' => self::fieldLabel($key),
                'value' => $safeValue,
            ];

            if (count($fields) >= self::MAX_GENERIC_FIELDS) {
                break;
            }
        }

        return $fields;
    }

    private static function scalarValue(mixed $value): int|float|string|bool|null
    {
        if (is_int($value) || is_bool($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }

        return is_string($value) ? self::safeText($value, self::MAX_FIELD_STRING_LENGTH) : null;
    }

    private static function fieldLabel(string $key): string
    {
        return Str::limit(
            ucfirst(strtolower(str_replace(['_', '-', '.', '/'], ' ', $key))),
            self::MAX_FIELD_LABEL_LENGTH,
            '',
        );
    }

    private static function safeText(mixed $value, int $maximum): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            ? null
            : mb_substr($value, 0, $maximum);
    }

    /**
     * @param  array<int|string, mixed>  $values
     */
    private static function isList(array $values): bool
    {
        $keys = array_keys($values);

        return $keys === [] || $keys === range(0, count($values) - 1);
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private static function putString(array &$target, string $key, ?string $value): void
    {
        if ($value !== null) {
            $target[$key] = $value;
        }
    }
}
