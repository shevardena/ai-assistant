<?php

namespace App\Services\Conversations\Blocks;

final readonly class TrackingBlock implements ConversationBlock
{
    public const MAX_FIELDS = 16;

    public const MAX_STATUS_LENGTH = 120;

    public const MAX_CARRIER_LENGTH = 255;

    public const MAX_REFERENCE_LENGTH = 255;

    public const MAX_DATE_LENGTH = 255;

    public const MAX_EVENT_LENGTH = 2000;

    public const MAX_URL_LENGTH = 2000;

    public const MAX_KEY_LENGTH = 255;

    public const MAX_LABEL_LENGTH = 255;

    public const MAX_VALUE_STRING_LENGTH = 2000;

    /**
     * @param  list<array{key: string, label: string, value: int|float|string|bool|null}>  $fields
     */
    public function __construct(
        public ?string $status,
        public ?string $carrier,
        public ?string $trackingReference,
        public ?string $estimatedDelivery,
        public ?string $latestEvent,
        public ?string $trackingUrl,
        public array $fields,
    ) {}

    public function type(): string
    {
        return ConversationBlockType::Tracking->value;
    }

    /**
     * @return array{type: 'tracking', data: array{status?: string, carrier?: string, tracking_reference?: string, estimated_delivery?: string, latest_event?: string, tracking_url?: string, fields: list<array{key: string, label: string, value: int|float|string|bool|null}>}}
     */
    public function toArray(): array
    {
        $data = [];

        foreach ([
            'status' => $this->status,
            'carrier' => $this->carrier,
            'tracking_reference' => $this->trackingReference,
            'estimated_delivery' => $this->estimatedDelivery,
            'latest_event' => $this->latestEvent,
            'tracking_url' => $this->trackingUrl,
        ] as $key => $value) {
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        $data['fields'] = $this->fields;

        return [
            'type' => ConversationBlockType::Tracking->value,
            'data' => $data,
        ];
    }
}
