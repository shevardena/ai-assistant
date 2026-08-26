<?php

namespace App\Services\Api;

use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\DataSource;

final readonly class RuntimeApiOperation
{
    public function __construct(
        public Bot $bot,
        public BotApiOperation $attachment,
        public ApiOperation $operation,
        public DataSource $dataSource,
    ) {}
}
