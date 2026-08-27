<?php

namespace App\Services\Api;

use App\Enums\ApiOperationMode;
use App\Models\Bot;
use App\Models\BotApiOperation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LiveOperationCapabilityService
{
    public function has(Bot $bot, string $capability): bool
    {
        return $this->query($bot, $capability)
            ->with('apiOperation')
            ->get()
            ->contains(fn (BotApiOperation $attachment): bool => $this->hasSafeResponseMapping($attachment));
    }

    private function hasSafeResponseMapping(BotApiOperation $attachment): bool
    {
        $operation = $attachment->apiOperation;

        if ($operation === null || ! is_array($operation->request_schema)) {
            return false;
        }

        $mapping = $operation->response_mapping;

        if (! is_array($mapping)) {
            return false;
        }

        $output = data_get($mapping, 'output');
        $collectionFields = data_get($mapping, 'collection.fields');

        return (is_array($output) && $output !== [])
            || (is_array($collectionFields) && $collectionFields !== []);
    }

    /** @return HasMany<BotApiOperation, Bot> */
    public function query(Bot $bot, string $capability): HasMany
    {
        return $bot->botApiOperations()
            ->where('tool_name', $capability)
            ->where('is_enabled', true)
            ->whereHas('bot', fn (Builder $query): Builder => $query->where('team_id', $bot->team_id))
            ->whereHas('apiOperation', function (Builder $query) use ($bot): Builder {
                return $query
                    ->where('is_enabled', true)
                    ->where('execution_mode', ApiOperationMode::Read->value)
                    ->whereNotNull('request_schema')
                    ->whereNotNull('response_mapping')
                    ->whereHas('dataSource', fn (Builder $source): Builder => $source
                        ->where('team_id', $bot->team_id)
                        ->whereIn('type', ['rest_api', 'graphql_api'])
                        ->liveUsable());
            });
    }
}
