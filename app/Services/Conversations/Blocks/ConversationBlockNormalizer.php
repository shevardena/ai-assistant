<?php

namespace App\Services\Conversations\Blocks;

use App\Enums\ToolRunStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ToolRun;
use App\Services\Ai\ToolRunPayloadSanitizer;
use Carbon\CarbonImmutable;

class ConversationBlockNormalizer
{
    public function __construct(private readonly ToolRunPayloadSanitizer $payloadSanitizer) {}

    /**
     * Normalize only block structures created by trusted Laravel code.
     *
     * @return list<array<string, mixed>>
     */
    public function normalize(mixed $blocks, mixed $legacyCards = null): array
    {
        $normalized = [];

        if (is_array($blocks)) {
            foreach ($blocks as $block) {
                $safeBlock = $this->normalizeBlock($block);

                if ($safeBlock !== null) {
                    $normalized[] = $safeBlock;
                }
            }
        }

        if ($normalized === [] && $legacyCards !== null) {
            return $this->fromProductCards(is_array($legacyCards) ? $legacyCards : []);
        }

        return $normalized;
    }

    /**
     * Normalize the persisted metadata for one message, including legacy cards.
     *
     * @return list<array<string, mixed>>
     */
    public function forMessage(Message $message): array
    {
        $metadata = $message->getAttribute('metadata');
        $metadata = is_array($metadata) ? $metadata : [];
        $blocks = $metadata['blocks'] ?? null;
        $legacy = $metadata['cards'] ?? $metadata['product_cards'] ?? null;

        if (is_array($legacy) && ($legacy['type'] ?? null) === ConversationBlockType::ProductCards->value) {
            return $this->reconcileAppointmentBlocks($message, $this->reconcileFormBlocks($message, $this->reconcileConfirmationBlocks($message, $this->normalize([$legacy]))));
        }

        return $this->reconcileAppointmentBlocks($message, $this->reconcileFormBlocks($message, $this->reconcileConfirmationBlocks($message, $this->normalize($blocks, $legacy))));
    }

    /**
     * @param  array<mixed>  $cards
     * @return list<array<string, mixed>>
     */
    public function fromProductCards(array $cards): array
    {
        $normalizedCards = $this->normalizeCards($cards);

        if ($normalizedCards === []) {
            return [];
        }

        return [(new ProductCardsBlock($normalizedCards))->toArray()];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeBlock(mixed $block): ?array
    {
        if ($block instanceof ConversationBlock) {
            $block = $block->toArray();
        }

        if (! is_array($block) || ! is_array($block['data'] ?? null)) {
            return null;
        }

        return match ($block['type'] ?? null) {
            ConversationBlockType::ProductCards->value => $this->normalizeProductCardsBlock($block),
            ConversationBlockType::Comparison->value => $this->normalizeComparisonBlock($block),
            ConversationBlockType::OrderStatus->value => $this->normalizeOrderStatusBlock($block),
            ConversationBlockType::Tracking->value => $this->normalizeTrackingBlock($block),
            ConversationBlockType::Locations->value => $this->normalizeLocationsBlock($block),
            ConversationBlockType::Form->value => $this->normalizeFormBlock($block),
            ConversationBlockType::AppointmentSlots->value => $this->normalizeAppointmentSlotsBlock($block),
            ConversationBlockType::Confirmation->value => $this->normalizeConfirmationBlock($block),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function normalizeLocationsBlock(array $block): ?array
    {
        $locations = $block['data']['locations'] ?? null;

        if (! is_array($locations)) {
            return null;
        }

        return LocationsBlock::fromMappedCollection($locations)?->toArray();
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function normalizeFormBlock(array $block): ?array
    {
        $data = $block['data'];
        $reference = $data['form_reference'] ?? null;
        $status = is_string($data['status'] ?? null)
            ? FormBlockStatus::tryFrom($data['status'])
            : null;

        if (! is_string($reference) || $status === null) {
            return null;
        }

        $definition = [
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'fields' => $data['fields'] ?? null,
            'submit_label' => $data['submit_label'] ?? null,
        ];

        return FormBlock::fromDefinition($reference, $definition, $status)?->toArray();
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function normalizeAppointmentSlotsBlock(array $block): ?array
    {
        $data = $block['data'];
        $reference = $data['appointment_reference'] ?? null;
        $timezone = $data['timezone'] ?? null;
        $status = is_string($data['status'] ?? null)
            ? AppointmentSlotsStatus::tryFrom($data['status'])
            : null;

        if (! is_string($reference) || ! is_string($timezone) || $status === null) {
            return null;
        }

        return AppointmentSlotsBlock::fromDefinition(
            $reference,
            is_string($data['title'] ?? null) ? $data['title'] : null,
            $timezone,
            $this->slotDefinitions($data['slots'] ?? null),
            $status,
            is_string($data['selected_slot_reference'] ?? null) ? $data['selected_slot_reference'] : null,
        )?->toArray();
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function normalizeTrackingBlock(array $block): ?array
    {
        $data = $block['data'];
        $status = $this->safeText($data['status'] ?? null, TrackingBlock::MAX_STATUS_LENGTH);
        $carrier = $this->safeText($data['carrier'] ?? null, TrackingBlock::MAX_CARRIER_LENGTH);
        $trackingReference = $this->safeText($data['tracking_reference'] ?? null, TrackingBlock::MAX_REFERENCE_LENGTH);
        $estimatedDelivery = $this->safeText($data['estimated_delivery'] ?? null, TrackingBlock::MAX_DATE_LENGTH);
        $latestEvent = $this->safeText($data['latest_event'] ?? null, TrackingBlock::MAX_EVENT_LENGTH);
        $trackingUrl = $this->url($data['tracking_url'] ?? null);
        $fields = $this->trackingFields($data['fields'] ?? null);

        if ($status === null
            && $carrier === null
            && $trackingReference === null
            && $estimatedDelivery === null
            && $latestEvent === null
            && $trackingUrl === null
            && $fields === []) {
            return null;
        }

        return (new TrackingBlock(
            status: $status,
            carrier: $carrier,
            trackingReference: $trackingReference,
            estimatedDelivery: $estimatedDelivery,
            latestEvent: $latestEvent,
            trackingUrl: $trackingUrl,
            fields: $fields,
        ))->toArray();
    }

    /**
     * @return list<array{key: string, label: string, value: int|float|string|bool|null}>
     */
    private function trackingFields(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach (array_values($fields) as $field) {
            if (count($normalized) >= TrackingBlock::MAX_FIELDS) {
                break;
            }

            if (! is_array($field)) {
                continue;
            }

            $key = $this->safeText($field['key'] ?? null, TrackingBlock::MAX_KEY_LENGTH);
            $label = $this->safeText($field['label'] ?? null, TrackingBlock::MAX_LABEL_LENGTH);
            $value = $this->trackingValue($field['value'] ?? null);

            if ($key === null || $label === null || isset($seen[$key])) {
                continue;
            }

            if ($value === null && array_key_exists('value', $field) && $field['value'] !== null) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = [
                'key' => $key,
                'label' => $label,
                'value' => $value,
            ];
        }

        return $normalized;
    }

    private function trackingValue(mixed $value): int|float|string|bool|null
    {
        if (is_int($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }

        return is_string($value)
            ? mb_substr($value, 0, TrackingBlock::MAX_VALUE_STRING_LENGTH)
            : null;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function normalizeOrderStatusBlock(array $block): ?array
    {
        $data = $block['data'];
        $status = $this->safeText($data['status'] ?? null, OrderStatusBlock::MAX_STATUS_LENGTH);
        $fields = $this->orderStatusFields($data['fields'] ?? null);

        if ($status === null && $fields === []) {
            return null;
        }

        return (new OrderStatusBlock($status, $fields))->toArray();
    }

    /**
     * @return list<array{key: string, label: string, value: int|float|string|bool|null}>
     */
    private function orderStatusFields(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach (array_slice(array_values($fields), 0, OrderStatusBlock::MAX_FIELDS) as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = $this->safeText($field['key'] ?? null, OrderStatusBlock::MAX_KEY_LENGTH);
            $label = $this->safeText($field['label'] ?? null, OrderStatusBlock::MAX_LABEL_LENGTH);
            $value = $this->orderStatusValue($field['value'] ?? null);

            if ($key === null || $label === null || isset($seen[$key])) {
                continue;
            }

            if ($value === null && array_key_exists('value', $field) && $field['value'] !== null) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = [
                'key' => $key,
                'label' => $label,
                'value' => $value,
            ];
        }

        return $normalized;
    }

    private function orderStatusValue(mixed $value): int|float|string|bool|null
    {
        if (is_int($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }

        return is_string($value)
            ? mb_substr($value, 0, OrderStatusBlock::MAX_VALUE_STRING_LENGTH)
            : null;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function normalizeComparisonBlock(array $block): ?array
    {
        $data = $block['data'];
        $items = $this->comparisonItems($data['items'] ?? null);

        if (count($items) < 2) {
            return null;
        }

        $fields = $this->comparisonFields($data['fields'] ?? null, count($items));

        return $fields === []
            ? null
            : (new ComparisonBlock($items, $fields))->toArray();
    }

    /**
     * @return list<array{product_reference: string, label: string}>
     */
    private function comparisonItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach (array_slice(array_values($items), 0, ComparisonBlock::MAX_ITEMS) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $reference = $this->safeText(
                $item['product_reference'] ?? null,
                ComparisonBlock::MAX_REFERENCE_LENGTH,
            );
            $label = $this->safeText($item['label'] ?? null, ComparisonBlock::MAX_LABEL_LENGTH);

            if ($reference === null || $label === null || isset($seen[$reference])) {
                continue;
            }

            $seen[$reference] = true;
            $normalized[] = [
                'product_reference' => $reference,
                'label' => $label,
            ];
        }

        return $normalized;
    }

    /**
     * @return list<array{key: string, label: string, values: list<int|float|string|bool|null>}>
     */
    private function comparisonFields(mixed $fields, int $itemCount): array
    {
        if (! is_array($fields)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach (array_slice(array_values($fields), 0, ComparisonBlock::MAX_FIELDS) as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = $this->safeText($field['key'] ?? null, ComparisonBlock::MAX_LABEL_LENGTH);
            $label = $this->safeText($field['label'] ?? null, ComparisonBlock::MAX_LABEL_LENGTH);
            $values = $field['values'] ?? null;

            if ($key === null || $label === null || ! is_array($values) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $safeValues = [];

            foreach (array_slice(array_values($values), 0, $itemCount) as $value) {
                $safeValues[] = $this->comparisonValue($value);
            }

            $safeValues = array_pad($safeValues, $itemCount, null);

            if (! in_array(true, array_map(static fn (mixed $value): bool => $value !== null, $safeValues), true)) {
                continue;
            }

            $normalized[] = [
                'key' => $key,
                'label' => $label,
                'values' => $safeValues,
            ];
        }

        return $normalized;
    }

    private function comparisonValue(mixed $value): int|float|string|bool|null
    {
        if (is_int($value) || is_bool($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }

        if (is_string($value)) {
            return mb_substr($value, 0, ComparisonBlock::MAX_VALUE_STRING_LENGTH);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function normalizeProductCardsBlock(array $block): ?array
    {
        $cards = $this->normalizeCards(
            is_array($block['data']['cards'] ?? null) ? $block['data']['cards'] : [],
        );

        return $cards === [] ? null : (new ProductCardsBlock($cards))->toArray();
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function normalizeConfirmationBlock(array $block): ?array
    {
        $data = $block['data'];
        $actionReference = $this->actionReference($data['action_reference'] ?? null);
        $summary = $this->text($data['summary'] ?? null, 500);
        $status = is_string($data['status'] ?? null)
            ? ConfirmationBlockStatus::tryFrom($data['status'])
            : null;

        if ($actionReference === null || $summary === null || $status === null) {
            return null;
        }

        return (new ConfirmationBlock(
            actionReference: $actionReference,
            summary: $summary,
            status: $status,
            result: $this->result($data['result'] ?? null),
        ))->toArray();
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function reconcileConfirmationBlocks(Message $message, array $blocks): array
    {
        $reconciled = [];

        foreach ($blocks as $block) {
            if (($block['type'] ?? null) !== ConversationBlockType::Confirmation->value) {
                $reconciled[] = $block;

                continue;
            }

            $actionReference = data_get($block, 'data.action_reference');
            $summary = data_get($block, 'data.summary');

            if (! is_string($actionReference) || ! is_string($summary)) {
                continue;
            }

            $botId = $message->conversation()->value('bot_id');
            $run = ToolRun::query()
                ->where('conversation_id', $message->conversation_id)
                ->where('bot_id', $botId)
                ->where('action_reference', $actionReference)
                ->first();
            $status = $run instanceof ToolRun
                ? $this->confirmationStatus($run)
                : ConfirmationBlockStatus::Failed;
            $result = $run instanceof ToolRun && $status === ConfirmationBlockStatus::Completed
                ? $this->result($run->safe_result)
                : [];

            $reconciled[] = (new ConfirmationBlock(
                actionReference: $actionReference,
                summary: $summary,
                status: $status,
                result: $result,
            ))->toArray();
        }

        return $reconciled;
    }

    /**
     * Reconcile saved form snapshots against the trusted conversation state.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function reconcileFormBlocks(Message $message, array $blocks): array
    {
        $hasForms = collect($blocks)->contains(
            static fn (array $block): bool => ($block['type'] ?? null) === ConversationBlockType::Form->value,
        );

        if (! $hasForms) {
            return $blocks;
        }

        $conversation = $message->conversation()->with('bot')->first();
        $memory = $conversation?->state()->value('memory');
        $memory = is_array($memory) ? $memory : [];
        $forms = is_array($memory['forms'] ?? null) ? $memory['forms'] : [];
        $active = is_array($memory['active_form'] ?? null) ? $memory['active_form'] : null;

        return array_map(function (array $block) use ($conversation, $forms, $active): array {
            if (($block['type'] ?? null) !== ConversationBlockType::Form->value) {
                return $block;
            }

            $reference = data_get($block, 'data.form_reference');
            $state = is_string($reference) && is_array($forms[$reference] ?? null)
                ? $forms[$reference]
                : (is_string($reference) && is_array($active) && ($active['form_reference'] ?? null) === $reference ? $active : null);

            if (! is_array($state)
                || ! $this->formStateBelongsToConversation($state, $conversation)
                || ! is_string($state['status'] ?? null)
                || ! is_array($state['schema'] ?? null)) {
                return $block;
            }

            $status = FormBlockStatus::tryFrom($state['status']);
            $form = $status === null
                ? null
                : FormBlock::fromDefinition($reference, $state['schema'], $status);

            return $form?->toArray() ?? $block;
        }, $blocks);
    }

    /**
     * Reconcile saved appointment snapshots against trusted conversation state.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function reconcileAppointmentBlocks(Message $message, array $blocks): array
    {
        $hasAppointments = collect($blocks)->contains(
            static fn (array $block): bool => ($block['type'] ?? null) === ConversationBlockType::AppointmentSlots->value,
        );

        if (! $hasAppointments) {
            return $blocks;
        }

        $conversation = $message->conversation()->with('bot')->first();
        $memory = $conversation?->state()->value('memory');
        $memory = is_array($memory) ? $memory : [];
        $appointments = is_array($memory['appointments'] ?? null) ? $memory['appointments'] : [];
        $active = is_array($memory['active_appointment'] ?? null) ? $memory['active_appointment'] : null;

        return array_map(function (array $block) use ($conversation, $appointments, $active): array {
            if (($block['type'] ?? null) !== ConversationBlockType::AppointmentSlots->value) {
                return $block;
            }

            $reference = data_get($block, 'data.appointment_reference');
            $state = is_string($reference) && is_array($appointments[$reference] ?? null)
                ? $appointments[$reference]
                : (is_string($reference) && is_array($active) && ($active['appointment_reference'] ?? null) === $reference ? $active : null);

            if (! is_array($state) || ! $this->appointmentStateBelongsToConversation($state, $conversation)) {
                return $block;
            }

            return $this->appointmentBlockFromState($state)?->toArray() ?? $block;
        }, $blocks);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function appointmentBlockFromState(array $state): ?AppointmentSlotsBlock
    {
        $status = AppointmentSlotsStatus::tryFrom((string) ($state['status'] ?? ''));

        if ($status === null
            || ! is_string($state['appointment_reference'] ?? null)
            || ! is_string($state['timezone'] ?? null)) {
            return null;
        }

        if ($status === AppointmentSlotsStatus::Pending && $this->appointmentExpired($state)) {
            $status = AppointmentSlotsStatus::Expired;
        }

        return AppointmentSlotsBlock::fromDefinition(
            $state['appointment_reference'],
            is_string($state['title'] ?? null) ? $state['title'] : null,
            $state['timezone'],
            $this->slotDefinitions($state['slots'] ?? null),
            $status,
            is_string($state['selected_slot_reference'] ?? null) ? $state['selected_slot_reference'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function appointmentExpired(array $state): bool
    {
        $expiresAt = $state['expires_at'] ?? null;

        if (! is_string($expiresAt)) {
            return true;
        }

        try {
            return CarbonImmutable::parse($expiresAt)->lessThanOrEqualTo(now());
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function appointmentStateBelongsToConversation(array $state, ?Conversation $conversation): bool
    {
        if ($conversation === null || $conversation->bot === null) {
            return false;
        }

        return (int) ($state['team_id'] ?? 0) === (int) $conversation->bot->team_id
            && (int) ($state['bot_id'] ?? 0) === (int) $conversation->bot_id
            && (int) ($state['conversation_id'] ?? 0) === (int) $conversation->id
            && (int) ($state['visitor_id'] ?? 0) === (int) ($conversation->visitor_id ?? 0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function slotDefinitions(mixed $slots): array
    {
        if (! is_array($slots)) {
            return [];
        }

        $definitions = [];

        foreach ($slots as $slot) {
            if (is_array($slot)) {
                $definitions[] = $slot;
            }
        }

        return $definitions;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function formStateBelongsToConversation(array $state, ?Conversation $conversation): bool
    {
        if ($conversation === null || $conversation->bot === null) {
            return false;
        }

        return (int) ($state['team_id'] ?? 0) === (int) $conversation->bot->team_id
            && (int) ($state['bot_id'] ?? 0) === (int) $conversation->bot_id
            && (int) ($state['conversation_id'] ?? 0) === (int) $conversation->id
            && (int) ($state['visitor_id'] ?? 0) === (int) ($conversation->visitor_id ?? 0);
    }

    private function confirmationStatus(ToolRun $run): ConfirmationBlockStatus
    {
        $value = $run->getAttribute('status');
        $status = $value instanceof ToolRunStatus
            ? $value
            : ToolRunStatus::tryFrom((string) $value);

        return match ($status) {
            ToolRunStatus::PendingConfirmation => ConfirmationBlockStatus::Pending,
            ToolRunStatus::Confirmed, ToolRunStatus::Executing => ConfirmationBlockStatus::Confirmed,
            ToolRunStatus::Completed => ConfirmationBlockStatus::Completed,
            ToolRunStatus::Cancelled => ConfirmationBlockStatus::Cancelled,
            default => ConfirmationBlockStatus::Failed,
        };
    }

    private function actionReference(mixed $value): ?string
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1
            ? $value
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function result(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $result */
        $result = $this->payloadSanitizer->sanitize($value);

        return $result;
    }

    /**
     * @param  array<mixed>  $cards
     * @return list<array<string, mixed>>
     */
    private function normalizeCards(array $cards): array
    {
        $normalized = [];

        foreach ($cards as $card) {
            $safeCard = $this->normalizeCard($card);

            if ($safeCard !== null) {
                $normalized[] = $safeCard;
            }
        }

        return $normalized;
    }

    /**
     * Keep only the safe ProductCard snapshot fields used by the renderer.
     *
     * @return array<string, mixed>|null
     */
    private function normalizeCard(mixed $card): ?array
    {
        if (! is_array($card)) {
            return null;
        }

        $id = $this->text($card['id'] ?? null, 255);
        $title = $this->text($card['title'] ?? null, 255);

        if ($id === null || $title === null) {
            return null;
        }

        return [
            'id' => $id,
            'image' => $this->url($card['image'] ?? null),
            'title' => $title,
            'subtitle' => $this->text($card['subtitle'] ?? null, 255),
            'description' => $this->text($card['description'] ?? null, 2000),
            'price' => $this->primitive($card['price'] ?? null),
            'old_price' => $this->primitive($card['old_price'] ?? null),
            'discount' => $this->primitive($card['discount'] ?? null),
            'url' => $this->url($card['url'] ?? null),
            'button_label' => $this->text($card['button_label'] ?? null, 120),
            'styles' => $this->styles($card['styles'] ?? null),
        ];
    }

    private function text(mixed $value, int $maximum): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maximum);
    }

    private function safeText(mixed $value, int $maximum): ?string
    {
        $value = $this->text($value, $maximum);

        return $value !== null && preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            ? null
            : $value;
    }

    private function url(mixed $value): ?string
    {
        $value = $this->text($value, 2000);

        if ($value === null) {
            return null;
        }

        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $value
            : null;
    }

    private function primitive(mixed $value): int|float|string|null
    {
        return is_int($value) || is_float($value) || is_string($value) ? $value : null;
    }

    /**
     * @return array{background_color: string, text_color: string, muted_text_color: string, price_color: string, old_price_color: string, discount_color: string, button_color: string, button_text_color: string}
     */
    private function styles(mixed $styles): array
    {
        $styles = is_array($styles) ? $styles : [];

        return [
            'background_color' => $this->color($styles['background_color'] ?? null, '#ffffff'),
            'text_color' => $this->color($styles['text_color'] ?? null, '#171717'),
            'muted_text_color' => $this->color($styles['muted_text_color'] ?? null, '#737373'),
            'price_color' => $this->color($styles['price_color'] ?? null, '#7c3aed'),
            'old_price_color' => $this->color($styles['old_price_color'] ?? null, '#737373'),
            'discount_color' => $this->color($styles['discount_color'] ?? null, '#7c3aed'),
            'button_color' => $this->color($styles['button_color'] ?? null, '#171717'),
            'button_text_color' => $this->color($styles['button_text_color'] ?? null, '#ffffff'),
        ];
    }

    private function color(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
            ? strtolower($value)
            : $fallback;
    }
}
