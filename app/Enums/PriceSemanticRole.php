<?php

namespace App\Enums;

enum PriceSemanticRole: string
{
    case CurrentPrice = 'current_price';
    case RegularPrice = 'regular_price';
    case DiscountPercent = 'discount_percent';

    public static function normalize(mixed $value, ?string $fieldKey = null): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'current_price', 'sale_price', 'promo_price', 'final_price' => self::CurrentPrice,
            'regular_price', 'original_price', 'old_price' => self::RegularPrice,
            'discount_percent', 'discount', 'sale_percent' => self::DiscountPercent,
            'price' => self::legacyPriceRole($fieldKey),
            default => self::tryFrom($normalized),
        };
    }

    public function supportsType(string $type): bool
    {
        return match ($this) {
            self::CurrentPrice, self::RegularPrice => $type === 'decimal',
            self::DiscountPercent => in_array($type, ['integer', 'decimal'], true),
        };
    }

    private static function legacyPriceRole(?string $fieldKey): self
    {
        $key = strtolower((string) $fieldKey);

        return str_contains($key, 'old') || str_contains($key, 'original') || str_contains($key, 'regular')
            ? self::RegularPrice
            : self::CurrentPrice;
    }
}
