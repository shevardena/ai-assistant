<?php

namespace App\Services\Ai\Contracts;

interface AiClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{output: list<array<string, mixed>>, output_text: string|null, usage: array<string, mixed>|null, id?: string|null, status?: string|null, finish_reason?: string|null}
     */
    public function createResponse(array $payload): array;
}
