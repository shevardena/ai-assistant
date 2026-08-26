<?php

namespace App\Services\Conversations;

use App\Services\Conversations\Blocks\AppointmentSlotsBlock;

final readonly class AppointmentSelection
{
    /**
     * @param  array{appointment_reference: string, selected_slot_reference: string, starts_at: string, ends_at: string|null, timezone: string}  $runtimeContext
     */
    public function __construct(
        public AppointmentSlotsBlock $block,
        public array $runtimeContext,
        public string $displayMessage,
    ) {}
}
