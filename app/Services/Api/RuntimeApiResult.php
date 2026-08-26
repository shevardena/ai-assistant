<?php

namespace App\Services\Api;

final readonly class RuntimeApiResult
{
    /**
     * @param  array<int|string, mixed>  $data
     */
    public function __construct(
        public bool $success,
        public array $data = [],
        public ?string $error = null,
        public ?string $message = null,
        public ?int $httpStatus = null,
    ) {}

    /**
     * @param  array<int|string, mixed>  $data
     */
    public static function success(array $data, int $httpStatus): self
    {
        return new self(
            success: true,
            data: $data,
            httpStatus: $httpStatus,
        );
    }

    public static function failure(string $error, string $message, ?int $httpStatus = null): self
    {
        return new self(
            success: false,
            error: $error,
            message: $message,
            httpStatus: $httpStatus,
        );
    }
}
