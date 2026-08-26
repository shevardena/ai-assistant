<?php

namespace App\Services\Actions;

use App\Enums\ToolRunStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class ActionPresentationService
{
    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'capture_lead' => 'Capture lead',
        'create_support_ticket' => 'Create support ticket',
        'book_appointment' => 'Book appointment',
        'add_to_cart' => 'Add to cart',
    ];

    /**
     * @var array<string, string>
     */
    private const STATUS_LABELS = [
        'pending_confirmation' => 'Pending confirmation',
        'confirmed' => 'Confirmed',
        'executing' => 'Executing',
        'completed' => 'Completed',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ];

    /**
     * @var array<string, string>
     */
    private const ERROR_LABELS = [
        'cancelled' => 'Action cancelled.',
        'slot_unavailable' => 'Slot no longer available.',
        'integration_unavailable' => 'Integration unavailable.',
        'out_of_stock' => 'Item is no longer available.',
        'not_found' => 'The requested resource was not found.',
        'invalid_request' => 'Request could not be completed.',
        'invalid_arguments' => 'The action details were invalid.',
        'preflight_failed' => 'The action could not be completed safely.',
        'action_not_available' => 'Action is no longer available.',
    ];

    /**
     * @return array<string, string>
     */
    public function labels(): array
    {
        return self::LABELS;
    }

    public function label(string $toolName): string
    {
        return self::LABELS[$toolName] ?? Str::headline($toolName);
    }

    public function statusLabel(ToolRunStatus|string|null $status): string
    {
        $value = $status instanceof ToolRunStatus ? $status->value : (string) $status;

        return self::STATUS_LABELS[$value] ?? Str::headline($value !== '' ? $value : 'Unknown');
    }

    /**
     * Convert an already-sanitized result into a customer-facing sentence.
     *
     * @param  array<string, mixed>|null  $result
     */
    public function resultSummary(string $toolName, ?array $result): ?string
    {
        if ($result === null) {
            return null;
        }

        return match ($toolName) {
            'capture_lead' => $this->referenceSummary('Lead submitted', $result['lead_reference'] ?? null),
            'create_support_ticket' => $this->referenceSummary('Ticket created', $result['ticket_reference'] ?? null),
            'book_appointment' => $this->appointmentSummary($result),
            'add_to_cart' => $this->cartSummary($result),
            default => 'Action completed successfully.',
        };
    }

    public function errorSummary(?string $errorCode): ?string
    {
        if ($errorCode === null || trim($errorCode) === '') {
            return null;
        }

        return self::ERROR_LABELS[$errorCode] ?? 'The action could not be completed.';
    }

    private function referenceSummary(string $label, mixed $reference): string
    {
        return is_string($reference) && trim($reference) !== ''
            ? $label.'. Reference: '.Str::limit(trim($reference), 80, '')
            : $label.'.';
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function appointmentSummary(array $result): string
    {
        $startAt = $result['start_at'] ?? null;

        if (! is_string($startAt) || trim($startAt) === '') {
            return 'Appointment booked.';
        }

        try {
            return 'Appointment booked for '.CarbonImmutable::parse($startAt)->format('M j, Y g:i A').'.';
        } catch (\Throwable) {
            return 'Appointment booked.';
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function cartSummary(array $result): string
    {
        $quantity = $result['item_quantity'] ?? null;

        return is_int($quantity) || is_float($quantity)
            ? 'Item added to cart. Quantity: '.(int) $quantity.'.'
            : 'Item added to cart.';
    }
}
