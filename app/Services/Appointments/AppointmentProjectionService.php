<?php

namespace App\Services\Appointments;

use App\Enums\ToolRunStatus;
use App\Models\Appointment;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\ToolRun;
use App\Services\Customers\CustomerIdentityResolutionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class AppointmentProjectionService
{
    public function __construct(private readonly CustomerIdentityResolutionService $customers) {}

    public function createFromCompletedRun(ToolRun $run): ?Appointment
    {
        return $this->project($run);
    }

    public function project(ToolRun $run): ?Appointment
    {
        if ($run->tool_name !== 'book_appointment' || ! $this->completed($run)) {
            return null;
        }

        $run->loadMissing(['bot:id,team_id', 'bot.team:id', 'conversation:id,bot_id,metadata']);
        $bot = $run->bot;

        if (! $bot instanceof Bot
            || (int) $run->team_id !== (int) $bot->team_id
            || ($run->conversation !== null && (int) $run->conversation->bot_id !== (int) $bot->id)
            || data_get($run->conversation?->metadata, 'source') === 'dashboard_preview') {
            return null;
        }

        $inputs = $this->inputs($run);
        $timezone = $this->text($inputs['timezone'] ?? null, 64);
        $startsAt = $this->date($inputs['start_at'] ?? null, $timezone);
        $endsAt = $this->date($inputs['ends_at'] ?? null, $timezone);
        $result = $this->array($run->safe_result);
        $customerResolution = $this->customers->resolve($bot->team, [
            'name' => $inputs['name'] ?? null,
            'email' => $inputs['email'] ?? null,
            'phone' => $inputs['phone'] ?? null,
            'source' => 'appointment',
        ], allowNameOnly: true);

        if ($customerResolution->customer !== null) {
            $customerResolution->customer->forceFill(['last_activity_at' => now()])->saveQuietly();
            $run->conversation?->update(['customer_id' => $customerResolution->customer->id]);
        }
        $attributes = [
            'public_id' => (string) Str::uuid(),
            'team_id' => $bot->team_id,
            'bot_id' => $bot->id,
            'conversation_id' => $run->conversation_id,
            'customer_id' => $customerResolution->customer?->id,
            'tool_run_id' => $run->id,
            'status' => 'scheduled',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => $timezone,
            'customer_name' => $this->text($inputs['name'] ?? null, 255),
            'customer_email' => $this->text($inputs['email'] ?? null, 320),
            'customer_phone' => $this->text($inputs['phone'] ?? null, 64),
            'provider_reference' => $this->text(
                $result['appointment_reference'] ?? $result['booking_reference'] ?? $result['provider_reference'] ?? $result['reference'] ?? null,
                255,
            ),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        Appointment::query()->insertOrIgnore([$attributes]);

        return Appointment::query()->where('tool_run_id', $run->id)->first();
    }

    /** @return array<string, mixed> */
    private function inputs(ToolRun $run): array
    {
        $safe = $this->array($run->safe_arguments);
        $preflight = $safe['__preflight'] ?? null;

        if (is_array($preflight)) {
            return $this->array($preflight);
        }

        return $this->mappedInputs($run, $safe);
    }

    /**
     * @param  array<string, mixed>  $safe
     * @return array<string, mixed>
     */
    private function mappedInputs(ToolRun $run, array $safe): array
    {
        $attachment = BotApiOperation::query()
            ->where('bot_id', $run->bot_id)
            ->where('api_operation_id', $run->api_operation_id)
            ->where('tool_name', 'book_appointment')
            ->first();
        $settings = $attachment?->getAttribute('settings');
        $mappings = is_array($settings) && is_array($settings['input_mapping'] ?? null) ? $settings['input_mapping'] : [];
        $inputs = [];

        foreach ($mappings as $modelInput => $mapping) {
            $argument = is_array($mapping) ? ($mapping['operation_argument'] ?? $mapping['argument'] ?? null) : null;

            if (is_string($modelInput) && is_string($argument) && array_key_exists($argument, $safe)) {
                $inputs[$modelInput] = $safe[$argument];
            }
        }

        return $inputs;
    }

    private function completed(ToolRun $run): bool
    {
        return ToolRunStatus::tryFrom((string) $run->getRawOriginal('status')) === ToolRunStatus::Completed;
    }

    /** @return array<string, mixed> */
    private function array(mixed $value): array
    {
        return is_array($value) ? array_filter($value, static fn (mixed $item, mixed $key): bool => is_string($key), ARRAY_FILTER_USE_BOTH) : [];
    }

    private function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Str::limit(trim($value), $limit, '');
    }

    private function date(mixed $value, ?string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $timezone ?: 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
