<?php

namespace App\Services\Logging;

use Illuminate\Support\Str;
use JsonException;

final class LogContextSanitizer
{
    public function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $childKey => $childValue) {
                $result[$childKey] = $this->sanitize(
                    $childValue,
                    is_string($childKey) ? $childKey : null,
                );
            }

            return $result;
        }

        if (is_string($value)) {
            if (preg_match('/\Adata:image\/[a-z0-9.+-]+;base64,/i', $value) === 1) {
                return '[IMAGE_DATA_REDACTED]';
            }

            return Str::limit($value, 2000);
        }

        return is_scalar($value) || $value === null ? $value : '[REDACTED]';
    }

    /** @return array{body: string, truncated: bool, original_bytes: int} */
    public function json(mixed $value, ?int $maximumBytes = null): array
    {
        try {
            $json = json_encode($this->sanitize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return [
                'body' => '[UNSERIALIZABLE]',
                'truncated' => false,
                'original_bytes' => 0,
            ];
        }

        $originalBytes = strlen($json);
        $maximumBytes ??= (int) config('chatbot_runtime.max_payload_bytes', 20000);

        if ($maximumBytes < 1 || $originalBytes <= $maximumBytes) {
            return ['body' => $json, 'truncated' => false, 'original_bytes' => $originalBytes];
        }

        return [
            'body' => substr($json, 0, $maximumBytes),
            'truncated' => true,
            'original_bytes' => $originalBytes,
        ];
    }

    public function url(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '[invalid-url]';
        }

        return $parts['scheme'].'://'.$parts['host'].($parts['port'] ?? null ? ':'.$parts['port'] : '').($parts['path'] ?? '');
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match('/(?:authorization|credential|password|secret|token|api[_-]?key|cookie)/i', $key) === 1;
    }
}
