<?php

namespace App\Services\SupportTickets;

use App\Enums\ToolRunStatus;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\SupportTicket;
use App\Models\ToolRun;
use App\Services\Customers\CustomerIdentityResolutionService;
use Illuminate\Support\Str;

final class SupportTicketProjectionService
{
    public function __construct(private readonly CustomerIdentityResolutionService $customers) {}

    public function createFromCompletedRun(ToolRun $run): ?SupportTicket
    {
        return $this->project($run);
    }

    public function project(ToolRun $run): ?SupportTicket
    {
        if ($run->tool_name !== 'create_support_ticket' || ! $this->completed($run)) {
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
        $result = $this->array($run->safe_result);
        $customerResolution = $this->customers->resolve($bot->team, [
            'name' => $inputs['name'] ?? null,
            'email' => $inputs['email'] ?? null,
            'source' => 'support_ticket',
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
            'status' => 'open',
            'subject' => $this->text($inputs['subject'] ?? null, 255),
            'summary' => $this->text($inputs['description'] ?? null, 2000),
            'customer_name' => $this->text($inputs['name'] ?? null, 255),
            'customer_email' => $this->text($inputs['email'] ?? null, 320),
            'provider_reference' => $this->text(
                $result['ticket_reference'] ?? $result['provider_reference'] ?? $result['reference'] ?? null,
                255,
            ),
            'external_url' => $this->url($result['support_url'] ?? $result['external_url'] ?? null),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        SupportTicket::query()->insertOrIgnore([$attributes]);

        return SupportTicket::query()->where('tool_run_id', $run->id)->first();
    }

    /** @return array<string, mixed> */
    private function inputs(ToolRun $run): array
    {
        $safe = $this->array($run->safe_arguments);
        $attachment = BotApiOperation::query()
            ->where('bot_id', $run->bot_id)
            ->where('api_operation_id', $run->api_operation_id)
            ->where('tool_name', 'create_support_ticket')
            ->first();
        $settings = $attachment?->getAttribute('settings');
        $mappings = is_array($settings) && is_array($settings['input_mapping'] ?? null) ? $settings['input_mapping'] : [];
        $inputs = [];

        foreach ($mappings as $modelInput => $mapping) {
            if (! is_string($modelInput) || ! is_array($mapping) || ($mapping['source'] ?? null) !== 'model_input') {
                continue;
            }

            $argument = $mapping['operation_argument'] ?? $mapping['argument'] ?? null;

            if (is_string($argument) && array_key_exists($argument, $safe)) {
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

    private function url(mixed $value): ?string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? Str::limit($value, 2000, '')
            : null;
    }
}
