<?php

namespace App\Services\Conversations\Blocks;

use Carbon\CarbonImmutable;
use DateTimeZone;

final readonly class AppointmentSlotsBlock implements ConversationBlock
{
    public const MAX_SLOTS = 30;

    public const MAX_DATE_GROUPS = 7;

    public const MAX_SLOTS_PER_DATE = 10;

    public const MAX_REFERENCE_LENGTH = 64;

    public const MAX_TITLE_LENGTH = 120;

    public const MAX_LABEL_LENGTH = 120;

    public function __construct(
        public string $appointmentReference,
        public ?string $title,
        public string $timezone,
        /** @var list<array{slot_reference: string, starts_at: string, ends_at: string|null, label?: string}> */
        public array $slots,
        public AppointmentSlotsStatus $status = AppointmentSlotsStatus::Pending,
        public ?string $selectedSlotReference = null,
    ) {}

    /**
     * Build a block from already-normalized trusted slot data.
     *
     * Provider-only fields are intentionally ignored here.
     *
     * @param  list<array<string, mixed>>  $slots
     */
    public static function fromDefinition(
        string $appointmentReference,
        ?string $title,
        string $timezone,
        array $slots,
        AppointmentSlotsStatus $status = AppointmentSlotsStatus::Pending,
        ?string $selectedSlotReference = null,
    ): ?self {
        if (! self::validReference($appointmentReference)
            || ! self::validTimezone($timezone)) {
            return null;
        }

        if ($title !== null && self::safeText($title, self::MAX_TITLE_LENGTH) === null) {
            return null;
        }

        $safeSlots = [];
        $seen = [];
        $dateGroups = [];

        foreach ($slots as $slot) {
            if (count($safeSlots) >= self::MAX_SLOTS) {
                break;
            }

            $reference = self::safeText($slot['slot_reference'] ?? null, self::MAX_REFERENCE_LENGTH);
            $startsAt = self::safeTimestamp($slot['starts_at'] ?? null);
            $endsAt = array_key_exists('ends_at', $slot) && $slot['ends_at'] !== null
                ? self::safeTimestamp($slot['ends_at'])
                : null;
            $label = array_key_exists('label', $slot) && $slot['label'] !== null
                ? self::safeText($slot['label'], self::MAX_LABEL_LENGTH)
                : null;

            if ($reference === null || $startsAt === null || isset($seen[$reference])) {
                continue;
            }

            if (($slot['ends_at'] ?? null) !== null && $endsAt === null) {
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

            $seen[$reference] = true;
            $dateGroups[$date] = ($dateGroups[$date] ?? 0) + 1;
            $safeSlot = [
                'slot_reference' => $reference,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ];

            if ($label !== null) {
                $safeSlot['label'] = $label;
            }

            $safeSlots[] = $safeSlot;
        }

        if ($safeSlots === []) {
            return null;
        }

        if ($selectedSlotReference !== null
            && (! self::validReference($selectedSlotReference) || ! isset($seen[$selectedSlotReference]))) {
            return null;
        }

        return new self(
            appointmentReference: $appointmentReference,
            title: $title,
            timezone: $timezone,
            slots: $safeSlots,
            status: $status,
            selectedSlotReference: $selectedSlotReference,
        );
    }

    public function type(): string
    {
        return ConversationBlockType::AppointmentSlots->value;
    }

    /**
     * @return array{type: 'appointment_slots', data: array<string, mixed>}
     */
    public function toArray(): array
    {
        $data = [
            'appointment_reference' => $this->appointmentReference,
            'timezone' => $this->timezone,
            'slots' => $this->slots,
            'status' => $this->status->value,
        ];

        if ($this->title !== null) {
            $data['title'] = $this->title;
        }

        if ($this->selectedSlotReference !== null) {
            $data['selected_slot_reference'] = $this->selectedSlotReference;
        }

        return [
            'type' => ConversationBlockType::AppointmentSlots->value,
            'data' => $data,
        ];
    }

    private static function validReference(string $reference): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $reference) === 1;
    }

    private static function validTimezone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    private static function safeText(mixed $value, int $maximum): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && mb_strlen($value) <= $maximum && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            ? $value
            : null;
    }

    private static function safeTimestamp(mixed $value): ?string
    {
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            return null;
        }

        try {
            $timestamp = CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }

        return $timestamp->format('Y-m-d\TH:i:sP');
    }
}
