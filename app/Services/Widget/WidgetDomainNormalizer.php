<?php

namespace App\Services\Widget;

use InvalidArgumentException;

class WidgetDomainNormalizer
{
    public function normalize(string $value): string
    {
        $value = trim($value);

        if ($value === '' || preg_match('/\s/', $value)) {
            throw new InvalidArgumentException('Enter a valid domain.');
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value)) {
            $parts = parse_url($value);

            if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
                throw new InvalidArgumentException('Enter a valid HTTP or HTTPS domain.');
            }

            if (isset($parts['user'], $parts['pass'], $parts['port']) || ($parts['path'] ?? '/') !== '/') {
                throw new InvalidArgumentException('Enter a domain without a path, port, or credentials.');
            }

            $value = (string) ($parts['host'] ?? '');
        } elseif (str_contains($value, '/') || str_contains($value, '?') || str_contains($value, '#') || str_contains($value, ':')) {
            throw new InvalidArgumentException('Enter a domain without a path, port, or protocol details.');
        }

        return $this->normalizeHost($value);
    }

    public function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B."));

        if ($host === '') {
            throw new InvalidArgumentException('Enter a valid domain.');
        }

        $isLocalhost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

        if ($isLocalhost) {
            if (! config('widget.allow_localhost', false)) {
                throw new InvalidArgumentException('Localhost domains are disabled.');
            }

            return $host;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if ($this->isForbiddenIp($host)) {
                throw new InvalidArgumentException('Private and reserved IP addresses are not allowed.');
            }

            return $host;
        }

        if (str_starts_with($host, '*.') || ! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/i', $host)) {
            throw new InvalidArgumentException('Enter a valid hostname.');
        }

        return $host;
    }

    private function isForbiddenIp(string $host): bool
    {
        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
