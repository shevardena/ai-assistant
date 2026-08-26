<?php

namespace App\Services\Api;

use App\Enums\ApiOperationMode;
use App\Enums\DataSourceStatus;
use App\Models\Bot;
use App\Models\BotApiOperation;
use Illuminate\Database\Eloquent\Builder;

class RuntimeApiOperationResolver
{
    public function resolve(Bot $bot, string $identifier): ?RuntimeApiOperation
    {
        return $this->resolveMode($bot, $identifier, ApiOperationMode::Read);
    }

    public function resolveRead(Bot $bot, string $identifier): ?RuntimeApiOperation
    {
        return $this->resolveMode($bot, $identifier, ApiOperationMode::Read);
    }

    public function resolveWrite(Bot $bot, string $identifier): ?RuntimeApiOperation
    {
        return $this->resolveMode($bot, $identifier, ApiOperationMode::Write);
    }

    private function resolveMode(Bot $bot, string $identifier, ApiOperationMode $mode): ?RuntimeApiOperation
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/', $identifier) !== 1) {
            return null;
        }

        $attachment = $bot->botApiOperations()
            ->where('tool_name', $identifier)
            ->where('is_enabled', true)
            ->whereHas('bot', fn (Builder $query): Builder => $query
                ->where('team_id', $bot->team_id))
            ->whereHas('apiOperation', function (Builder $query) use ($bot, $mode): Builder {
                return $query
                    ->where('is_enabled', true)
                    ->where('execution_mode', $mode->value)
                    ->whereHas('dataSource', fn (Builder $dataSource): Builder => $dataSource
                        ->where('team_id', $bot->team_id)
                        ->whereIn('type', ['rest_api', 'graphql_api'])
                        ->where('status', DataSourceStatus::Ready->value));
            })
            ->with('apiOperation.dataSource.credentials')
            ->first();

        if (! $attachment instanceof BotApiOperation
            || ! $attachment->apiOperation
            || ! $attachment->apiOperation->dataSource) {
            return null;
        }

        return new RuntimeApiOperation(
            bot: $bot,
            attachment: $attachment,
            operation: $attachment->apiOperation,
            dataSource: $attachment->apiOperation->dataSource,
        );
    }
}
