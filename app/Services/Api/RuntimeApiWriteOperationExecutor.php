<?php

namespace App\Services\Api;

final class RuntimeApiWriteOperationExecutor
{
    public function __construct(
        private readonly RuntimeApiOperationExecutor $executor,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function validate(RuntimeApiOperation $runtimeOperation, array $arguments): RuntimeApiResult
    {
        return $this->executor->validateWrite($runtimeOperation, $arguments);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(
        RuntimeApiOperation $runtimeOperation,
        array $arguments,
        string $idempotencyKey,
    ): RuntimeApiResult {
        return $this->executor->executeWrite($runtimeOperation, $arguments, $idempotencyKey);
    }
}
