<?php

namespace App\Services\Conversations;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Str;

final class AppointmentSlotNormalizer
{
    public const MAX_SLOTS = 30;

    public const MAX_DATE_GROUPS = 7;

    public const MAX_SLOTS_PER_DATE = 10;

    /**
     * Normalize a bounded collection returned by a trusted availability mapping.
     *
     * @param  array<mixed>  $rawSlots
     * @return list<array{slot_reference: string, provider_slot_reference: string, starts_at: string, ends_at: string|null, label: string|null}>
     */
    public function normalize(
        array $rawSlots,
        string $timezone,
        string $referenceField = 'slot_reference',
        string $startsAtField = 'starts_at',
        string $endsAtField = 'ends_at',
        string $labelField = 'label',
    ): array {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return [];
        }

        $normalized = [];
        $seenProviderReferences = [];
        $dateGroups = [];

        foreach ($rawSlots as $rawSlot) {
            if (count($normalized) >= self::MAX_SLOTS || ! is_array($rawSlot)) {
                break;
            }

            $providerReference = $this->safeText($rawSlot[$referenceField] ?? null, 255);
            $startsAt = $this->timestamp($rawSlot[$startsAtField] ?? null, $timezone);
            $endsAt = $rawSlot[$endsAtField] ?? null;
            $endsAt = $endsAt === null ? null : $this->timestamp($endsAt, $timezone);
            $label = $rawSlot[$labelField] ?? null;
            $label = $label === null ? null : $this->safeText($label, 120);

            if ($providerReference === null
                || $startsAt === null
                || isset($seenProviderReferences[$providerReference])) {
                continue;
            }

            if (($rawSlot[$endsAtField] ?? null) !== null && $endsAt === null) {
                continue;
            }

            if ($endsAt !== null && CarbonImmutable::parse($endsAt)->lessThanOrEqualTo(CarbonImmutable::parse($startsAt))) {
                continue;
            }

            $date = CarbonImmutable::parse($startsAt)->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');

            if (! isset($dateGroups[$date]) && count($dateGroups) >= self::MAX_DATE_GROUPS) {
                continue;
            }

            if (($dateGroups[$date] ?? 0) >= self::MAX_SLOTS_PER_DATE) {
                continue;
            }

            $seenProviderReferences[$providerReference] = true;
            $dateGroups[$date] = ($dateGroups[$date] ?? 0) + 1;
            $normalized[] = [
                'slot_reference' => (string) Str::uuid(),
                'provider_slot_reference' => $providerReference,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'label' => $label,
            ];
        }

        usort($normalized, static fn (array $left, array $right): int => strcmp($left['starts_at'], $right['starts_at']));

        return $normalized;
    }

    private function timestamp(mixed $value, string $timezone): ?string
    {
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            return null;
        }

        try {
            $timestamp = CarbonImmutable::parse($value)->setTimezone(new DateTimeZone($timezone));
        } catch (\Throwable) {
            return null;
        }

        return $timestamp->format('Y-m-d\TH:i:sP');
    }

    private function safeText(mixed $value, int $maximum): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && mb_strlen($value) <= $maximum && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            ? $value
            : null;
    }
}
